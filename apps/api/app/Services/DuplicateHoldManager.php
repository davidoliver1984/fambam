<?php

namespace App\Services;

use App\Enums\DuplicateResolution;
use App\Models\MediaUploadDuplicateHold;
use App\Models\Photo;
use App\Models\User;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DuplicateHoldManager
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ExactDuplicateDetector $duplicates,
        private readonly AlbumContributionFinalizer $finalizer,
        private readonly AlbumManager $albums,
        private readonly AuditRecorder $audit,
    ) {}

    /** @param array<string, mixed> $input */
    public function resolve(
        MediaUploadDuplicateHold $hold,
        User $actor,
        array $input,
        Request $request,
    ): ?Photo {
        return DB::transaction(function () use ($hold, $actor, $input, $request): ?Photo {
            $locked = MediaUploadDuplicateHold::query()->lockForUpdate()->findOrFail($hold->id);
            if ($locked->resolved_at !== null) {
                throw ValidationException::withMessages(['hold' => ['This duplicate decision has already been resolved.']]);
            }

            $upload = $locked->mediaUpload()->lockForUpdate()->firstOrFail();
            $album = $locked->targetAlbum()->lockForUpdate()->firstOrFail();
            $membership = $this->tenantContext->membership();
            if ($upload->user_id !== $actor->id || ! $this->finalizer->mayContribute($album, $membership)) {
                abort(403);
            }

            $this->duplicates->lock($upload);
            $matches = $this->duplicates->visibleMatches($upload, $actor, $membership);
            $resolution = DuplicateResolution::from((string) $input['resolution']);
            $photo = null;

            if ($resolution === DuplicateResolution::UseExisting) {
                $photo = $matches->firstWhere('id', (string) ($input['existing_photo_id'] ?? ''));
                if ($photo === null) {
                    throw ValidationException::withMessages([
                        'existing_photo_id' => ['The selected duplicate Photo is unavailable.'],
                    ]);
                }
                $this->albums->addPhoto(
                    $album,
                    $photo,
                    $actor,
                    (bool) ($input['confirm_visibility_widening'] ?? false),
                    $request,
                );
            } elseif ($resolution === DuplicateResolution::CreateNew) {
                $disclosedIds = $input['disclosed_photo_ids'] ?? [];
                $disclosedMatches = $matches->whereIn('id', $disclosedIds)->values();
                if ($disclosedMatches->count() !== count($disclosedIds)) {
                    throw ValidationException::withMessages([
                        'disclosed_photo_ids' => ['The disclosed duplicate Photos have changed. Refresh and try again.'],
                    ]);
                }
                $photo = $this->finalizer->completeNewContribution(
                    $upload,
                    $album,
                    $membership,
                    TenantOperationContext::fromRequest($this->tenantContext->familySpace(), $actor, $request),
                    false,
                );
                foreach ($disclosedMatches as $match) {
                    $decision = $this->duplicates->recordSeparateDecision($photo, $match, $actor);
                    $this->audit->record('photo_duplicate.decision_recorded', $decision, $actor, $request, [
                        'resolution' => $resolution->value,
                    ]);
                }
                $this->duplicates->generateCandidatesFor($photo);
            }

            $locked->update([
                'resolution' => $resolution,
                'chosen_photo_id' => $resolution === DuplicateResolution::UseExisting ? $photo->id : null,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
            ]);
            $this->audit->record('media_upload_duplicate_hold.resolved', $locked, $actor, $request, [
                'resolution' => $resolution->value,
                'photo_id' => $photo?->id,
            ]);

            return $photo;
        });
    }
}
