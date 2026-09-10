<?php

namespace App\Services;

use App\Enums\FamilyExportScope;
use App\Enums\FamilyExportState;
use App\Enums\FamilySpaceRole;
use App\Enums\NotificationCategory;
use App\Enums\NotificationOutcome;
use App\Exports\FamilyArchiveBuilder;
use App\Exports\FamilyExportSelection;
use App\Jobs\GenerateFamilyExport;
use App\Jobs\SendFamilyExportNotification;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Models\FamilyExport;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\NotificationCandidate;
use App\Models\NotificationDelivery;
use App\Models\User;
use App\Notifications\FamilyActivityNotification;
use App\Storage\FamilyStorageKey;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FamilyExportManager
{
    public function __construct(
        private readonly FamilyExportSelectionService $selections,
        private readonly FamilyArchiveBuilder $archives,
        private readonly MediaDeliveryUrlSigner $signer,
        private readonly MediaObjectStorage $storage,
        private readonly AuditRecorder $audit,
        private readonly DatabaseTenantContext $databaseContext,
        private readonly TenantContext $tenantContext,
        private readonly Dispatcher $notifications,
    ) {}

    public function request(FamilySpace $familySpace, User $actor, FamilyExportScope $scope, Request $request): FamilyExport
    {
        $context = TenantOperationContext::fromRequest($familySpace, $actor, $request);
        $export = DB::transaction(function () use ($familySpace, $actor, $scope, $request, $context): FamilyExport {
            $export = new FamilyExport([
                'family_space_id' => $familySpace->id,
                'requested_by' => $actor->id,
                'scope' => $scope,
                'state' => FamilyExportState::Pending,
            ]);
            $export->id = (string) Str::ulid();
            $export->object_key = FamilyStorageKey::for($familySpace, "family-exports/{$export->id}.zip");
            $export->save();
            $this->audit->record('family_export.requested', $export, $actor, $request, [
                'scope' => $scope->value,
            ], $context);

            DB::afterCommit(fn () => GenerateFamilyExport::dispatch($context->toArray(), $export->id));

            return $export;
        });

        return $export;
    }

    /** @return Collection<int, FamilyExport> */
    public function allFor(User $actor): Collection
    {
        return FamilyExport::query()->with('requester:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('requested_by', $actor->id)->latest()->get();
    }

    public function findFor(User $actor, string $id): FamilyExport
    {
        return FamilyExport::query()->with('requester:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('requested_by', $actor->id)->findOrFail($id);
    }

    public function beginGeneration(TenantOperationContext $context, string $exportId): ?FamilyExportSelection
    {
        try {
            return DB::transaction(function () use ($context, $exportId): ?FamilyExportSelection {
                [$export, $requester] = $this->establish($context, $exportId, true);
                if ($export === null || $requester === null || ! in_array($export->state, [
                    FamilyExportState::Pending,
                    FamilyExportState::Processing,
                    FamilyExportState::Failed,
                ], true)) {
                    return null;
                }
                if ($export->scope === FamilyExportScope::FamilySpaceFull
                    && $this->tenantContext->membership()->role !== FamilySpaceRole::Owner) {
                    throw new AuthorizationException('Only the current Family Space Owner may generate a full export.');
                }

                $export->update(['state' => FamilyExportState::Processing, 'failure_reason' => null]);

                return $this->selections->resolve($export, $requester);
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function generate(TenantOperationContext $context, string $exportId): void
    {
        $selection = $this->beginGeneration($context, $exportId);
        if ($selection === null) {
            return;
        }

        [$export, $requester] = DB::transaction(function () use ($context, $exportId): array {
            return $this->establish($context, $exportId);
        });
        if ($export === null || $requester === null) {
            return;
        }

        $archive = $this->archives->buildAndStore($context, $export, $requester, $selection);
        $this->markReady($context, $exportId, $archive->sha256, $archive->byteSize, $archive->photoCount);
    }

    public function authorizeDownload(FamilyExport $export, User $actor, Request $request): MediaDeliveryAuthorization
    {
        if ($export->requested_by !== $actor->id) {
            throw new AuthorizationException;
        }
        if ($export->scope === FamilyExportScope::FamilySpaceFull
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Owner) {
            throw new AuthorizationException;
        }
        if ($export->state !== FamilyExportState::Ready
            || $export->expires_at === null || ! $export->expires_at->isFuture()) {
            throw ValidationException::withMessages(['export' => ['This family archive is not available.']]);
        }

        $authorization = $this->signer->authorizeRead(
            $export->object_key,
            'application/zip',
            now()->addMinutes((int) config('family-exports.download_ttl_minutes')),
            MediaSigningAudience::Browser,
        );
        $this->audit->record('family_export.download_authorised', $export, $actor, $request, [
            'scope' => $export->scope->value,
            'authorization_expires_at' => $authorization->expiresAt->toAtomString(),
        ]);

        return $authorization;
    }

    public function markReady(TenantOperationContext $context, string $exportId, string $sha256, int $byteSize, int $photoCount): void
    {
        $this->transitionTerminal($context, $exportId, FamilyExportState::Ready, [
            'archive_sha256' => $sha256,
            'byte_size' => $byteSize,
            'photo_count' => $photoCount,
            'failure_reason' => null,
            'expires_at' => now()->addHours((int) config('family-exports.lifetime_hours')),
        ]);
    }

    public function markFailed(TenantOperationContext $context, string $exportId): void
    {
        $this->transitionTerminal($context, $exportId, FamilyExportState::Failed, [
            'failure_reason' => 'generation_failed',
        ]);
    }

    public function expire(TenantOperationContext $context, string $exportId): void
    {
        try {
            $export = DB::transaction(function () use ($context, $exportId): ?FamilyExport {
                [$candidate] = $this->establish($context, $exportId);

                return $candidate;
            });
            if ($export === null || $export->state !== FamilyExportState::Ready
                || $export->expires_at === null || $export->expires_at->isFuture()) {
                return;
            }

            $this->storage->delete($export->object_key);
            DB::transaction(function () use ($context, $exportId): void {
                [$locked] = $this->establish($context, $exportId, true);
                if ($locked === null || $locked->state !== FamilyExportState::Ready
                    || $locked->expires_at === null || $locked->expires_at->isFuture()) {
                    return;
                }
                $locked->update(['state' => FamilyExportState::Expired]);
                $this->audit->record('family_export.expired', $locked, operationContext: $context);
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    public function deliverTerminalNotification(
        TenantOperationContext $context,
        string $exportId,
        FamilyExportState $terminalState,
    ): void {
        try {
            $sourceActionId = $this->terminalActionId($exportId, $terminalState);
            $prepared = DB::transaction(function () use ($context, $exportId, $sourceActionId): ?array {
                [$export, $requester] = $this->establish($context, $exportId);
                if ($export === null || $requester === null) {
                    return null;
                }
                $candidate = NotificationCandidate::query()->lockForUpdate()->where([
                    'recipient_user_id' => $requester->id,
                    'category' => NotificationCategory::Export,
                    'source_action_id' => $sourceActionId,
                ])->first();
                if ($candidate === null) {
                    return null;
                }
                $typed = ['family_export_id' => $export->id];
                $notification = FamilyNotification::query()->firstOrCreate([
                    'family_space_id' => $export->family_space_id,
                    'recipient_user_id' => $requester->id,
                    'category' => NotificationCategory::Export,
                    'source_action_id' => $sourceActionId,
                ], $typed);
                $delivery = NotificationDelivery::query()->firstOrCreate([
                    'family_space_id' => $export->family_space_id,
                    'recipient_user_id' => $requester->id,
                    'category' => NotificationCategory::Export,
                    'source_action_id' => $sourceActionId,
                    'channel' => 'mail',
                ], [
                    'notification_id' => $notification->id,
                    'status' => 'pending',
                    ...$typed,
                ]);
                $candidate->update([
                    'in_app_outcome' => NotificationOutcome::Created,
                    'email_outcome' => NotificationOutcome::Created,
                    'evaluated_at' => $candidate->evaluated_at ?? now(),
                ]);
                if ($delivery->status === 'sent') {
                    return null;
                }

                return [$requester, $delivery->id];
            });
            if ($prepared === null) {
                return;
            }
            [$requester, $deliveryId] = $prepared;
            try {
                $message = $terminalState === FamilyExportState::Ready
                    ? 'Your fambam export is ready.'
                    : 'Your fambam export could not be completed.';
                $this->notifications->send($requester, new FamilyActivityNotification(
                    $message,
                    rtrim((string) config('app.web_url'), '/').'/account',
                ));
                $this->recordDeliveryOutcome($context, $deliveryId, [
                    'status' => 'sent',
                    'attempted_at' => now(),
                    'sent_at' => now(),
                    'failure_reason' => null,
                ]);
            } catch (\Throwable $exception) {
                $this->recordDeliveryOutcome($context, $deliveryId, [
                    'status' => 'failed',
                    'attempted_at' => now(),
                    'failure_reason' => 'delivery_failed',
                ]);
                throw $exception;
            }
        } finally {
            $this->tenantContext->clear();
        }
    }

    /** @param array<string, mixed> $values */
    private function transitionTerminal(TenantOperationContext $context, string $exportId, FamilyExportState $state, array $values): void
    {
        try {
            DB::transaction(function () use ($context, $exportId, $state, $values): void {
                [$export, $requester] = $this->establish($context, $exportId, true);
                if ($export === null || $requester === null || $export->state === $state) {
                    return;
                }
                $mayTransition = $state === FamilyExportState::Ready
                    ? $export->state === FamilyExportState::Processing
                    : in_array($export->state, [FamilyExportState::Pending, FamilyExportState::Processing], true);
                if (! $mayTransition) {
                    return;
                }
                $export->update(['state' => $state, ...$values]);
                $this->audit->record("family_export.{$state->value}", $export, metadata: [
                    'scope' => $export->scope->value,
                    ...($state === FamilyExportState::Failed ? ['reason' => 'generation_failed'] : []),
                ], operationContext: $context);
                $sourceActionId = $this->terminalActionId($export->id, $state);
                NotificationCandidate::query()->firstOrCreate([
                    'family_space_id' => $export->family_space_id,
                    'recipient_user_id' => $requester->id,
                    'category' => NotificationCategory::Export,
                    'source_action_id' => $sourceActionId,
                ], [
                    'in_app_outcome' => NotificationOutcome::Pending,
                    'email_outcome' => NotificationOutcome::Pending,
                ]);
                DB::afterCommit(fn () => SendFamilyExportNotification::dispatch(
                    $context->toArray(),
                    $export->id,
                    $state,
                ));
            });
        } finally {
            $this->tenantContext->clear();
        }
    }

    private function terminalActionId(string $exportId, FamilyExportState $state): string
    {
        return substr(hash('sha256', "{$exportId}:{$state->value}"), 0, 26);
    }

    /** @param array<string, mixed> $values */
    private function recordDeliveryOutcome(TenantOperationContext $context, string $deliveryId, array $values): void
    {
        DB::transaction(function () use ($context, $deliveryId, $values): void {
            $this->databaseContext->establishUser($context->actorUserId);
            $this->databaseContext->establishFamilySpace($context->familySpaceId);
            NotificationDelivery::query()->whereKey($deliveryId)->update($values);
        });
    }

    /** @return array{FamilyExport|null, User|null} */
    private function establish(TenantOperationContext $context, string $exportId, bool $lock = false): array
    {
        $this->databaseContext->establishUser($context->actorUserId);
        $this->databaseContext->establishFamilySpace($context->familySpaceId);
        $query = FamilyExport::query()->with('requester');
        $export = ($lock ? $query->lockForUpdate() : $query)->find($exportId);
        $requester = $export?->requester;
        $familySpace = $export === null ? null : FamilySpace::query()->find($export->family_space_id);
        $membership = $requester === null || $familySpace === null ? null : FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)->where('user_id', $requester->id)
            ->where('state', 'active')->first();
        if ($familySpace !== null && $membership !== null) {
            $this->tenantContext->establish($familySpace, $membership, $requester);
        }

        return [$export, $requester];
    }
}
