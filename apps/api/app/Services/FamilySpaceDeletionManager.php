<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Media\FamilyMediaStorageCleaner;
use App\Models\FamilyCircle;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FamilySpaceDeletionManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly DatabaseTenantContext $databaseTenantContext,
        private readonly FamilyMediaStorageCleaner $mediaStorageCleaner,
    ) {}

    public function request(FamilySpace $familySpace, User $actor, Request $request): FamilySpace
    {
        return DB::transaction(function () use ($familySpace, $actor, $request): FamilySpace {
            $locked = FamilySpace::query()->lockForUpdate()->findOrFail($familySpace->id);
            $this->ensureOwner($locked, $actor);

            if ($locked->status === FamilySpaceStatus::DeletionRequested) {
                return $locked;
            }

            if ($locked->status !== FamilySpaceStatus::Active) {
                $this->fail('This Family Space cannot enter pending deletion from its current state.');
            }

            $requestedAt = now();
            $locked->update([
                'status' => FamilySpaceStatus::DeletionRequested,
                'deletion_requested_at' => $requestedAt,
                'deletion_requested_by' => $actor->id,
                'scheduled_deletion_at' => $requestedAt->copy()->addDays(
                    (int) config('family-spaces.deletion_grace_days'),
                ),
            ]);
            $this->audit->record('family_space.deletion_requested', $locked, $actor, $request, [
                'scheduled_deletion_at' => $locked->scheduled_deletion_at?->toAtomString(),
            ]);

            return $locked;
        });
    }

    public function cancel(FamilySpace $familySpace, User $actor, Request $request): FamilySpace
    {
        return DB::transaction(function () use ($familySpace, $actor, $request): FamilySpace {
            $locked = FamilySpace::query()->lockForUpdate()->findOrFail($familySpace->id);
            $this->ensureOwner($locked, $actor);

            if ($locked->status === FamilySpaceStatus::Active) {
                return $locked;
            }

            if ($locked->status !== FamilySpaceStatus::DeletionRequested) {
                $this->fail('Deletion can no longer be cancelled for this Family Space.');
            }

            $locked->update([
                'status' => FamilySpaceStatus::Active,
                'deletion_requested_at' => null,
                'deletion_requested_by' => null,
                'scheduled_deletion_at' => null,
            ]);
            $this->audit->record('family_space.deletion_cancelled', $locked, $actor, $request);

            return $locked;
        });
    }

    public function teardown(TenantOperationContext $context): void
    {
        $claimed = DB::transaction(function () use ($context): bool {
            $this->establishTeardownContext($context);
            $familySpace = FamilySpace::query()->lockForUpdate()->find($context->familySpaceId);

            if ($familySpace === null
                || $familySpace->status === FamilySpaceStatus::Active
                || $familySpace->status === FamilySpaceStatus::Deleted) {
                return false;
            }

            if ($familySpace->status === FamilySpaceStatus::DeletionRequested) {
                if ($familySpace->scheduled_deletion_at?->isFuture() !== false) {
                    return false;
                }

                $familySpace->update(['status' => FamilySpaceStatus::Deleting]);
            }

            return true;
        });

        if (! $claimed) {
            return;
        }

        $this->mediaStorageCleaner->deleteFamilyMedia($context->familySpaceId);

        DB::transaction(function () use ($context): void {
            $this->establishTeardownContext($context);
            $familySpace = FamilySpace::query()->lockForUpdate()->find($context->familySpaceId);

            if ($familySpace === null || $familySpace->status === FamilySpaceStatus::Deleted) {
                return;
            }

            if ($familySpace->status !== FamilySpaceStatus::Deleting) {
                return;
            }

            $familySpace->update(['status' => FamilySpaceStatus::Deleted]);
            FamilyCircle::query()
                ->where('family_space_id', $familySpace->id)
                ->delete();
            Photo::query()
                ->where('family_space_id', $familySpace->id)
                ->delete();
            Tag::query()
                ->where('family_space_id', $familySpace->id)
                ->delete();
            MediaUpload::query()
                ->where('family_space_id', $familySpace->id)
                ->delete();
            Person::withTrashed()
                ->where('family_space_id', $familySpace->id)
                ->forceDelete();
            FamilySpaceMembership::query()
                ->where('family_space_id', $familySpace->id)
                ->where('state', MembershipState::Active->value)
                ->update([
                    'state' => MembershipState::Removed->value,
                    'removed_at' => now(),
                    'removed_by' => $context->actorUserId,
                    'updated_at' => now(),
                ]);
            $actor = User::query()->find($context->actorUserId);
            $this->audit->record(
                'family_space.deleted',
                $familySpace,
                $actor,
                metadata: ['teardown' => 'completed'],
                operationContext: $context,
            );
        });
    }

    private function establishTeardownContext(TenantOperationContext $context): void
    {
        $this->databaseTenantContext->establishUser($context->actorUserId);
        $this->databaseTenantContext->establishFamilySpace(
            $context->familySpaceId,
            authoritativeOperation: 'deletion_teardown',
        );
    }

    private function ensureOwner(FamilySpace $familySpace, User $actor): void
    {
        $owner = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('user_id', $actor->id)
            ->where('role', FamilySpaceRole::Owner->value)
            ->where('state', MembershipState::Active->value)
            ->exists();

        if (! $owner) {
            throw new AuthorizationException;
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['family_space' => [$message]]);
    }
}
