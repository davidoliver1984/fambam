<?php

namespace App\FaceRecognition;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FaceObservationReviewDisposition;
use App\Enums\FamilySpaceRole;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\FaceObservationReview;
use App\Models\Photo;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Services\AuditRecorder;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FaceObservationReviewManager
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly PhotoPolicy $photos,
        private readonly AuditRecorder $audit,
    ) {}

    public function leaveUnidentified(
        FaceObservation $observation,
        User $actor,
        Request $request,
    ): FaceObservationReview {
        return DB::transaction(function () use ($observation, $actor, $request): FaceObservationReview {
            $this->authorize($actor);
            $locked = FaceObservation::query()->lockForUpdate()->findOrFail($observation->id);
            if ($locked->family_space_id !== $this->tenant->familySpace()->id) {
                throw new AuthorizationException;
            }
            $photo = $this->photoFor($locked);
            if (! $this->photos->view($actor, $photo)) {
                throw new AuthorizationException;
            }

            $activeAssignments = FaceIdentityAssignment::query()
                ->where('face_observation_id', $locked->id)
                ->whereIn('status', [
                    FaceIdentityAssignmentStatus::Pending,
                    FaceIdentityAssignmentStatus::Approved,
                ])->lockForUpdate()->get();
            if ($activeAssignments->contains(
                fn (FaceIdentityAssignment $assignment): bool => $assignment->status === FaceIdentityAssignmentStatus::Approved,
            )) {
                throw ValidationException::withMessages([
                    'face_observation' => ['An approved identity must be revisited through the identity-review workflow.'],
                ]);
            }
            FaceIdentityAssignment::query()->whereIn('id', $activeAssignments->pluck('id'))
                ->update([
                    'status' => FaceIdentityAssignmentStatus::Withdrawn,
                    'resolved_by' => $actor->id,
                    'resolved_at' => now(),
                ]);

            $review = FaceObservationReview::query()->updateOrCreate(
                ['face_observation_id' => $locked->id],
                [
                    'family_space_id' => $locked->family_space_id,
                    'disposition' => FaceObservationReviewDisposition::LeftUnidentified,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ],
            );
            $this->audit->record('face_observation.left_unidentified', $review, $actor, $request, [
                'face_observation_id' => $locked->id,
                'photo_id' => $photo->id,
            ]);

            return $review;
        });
    }

    private function authorize(User $actor): void
    {
        $membership = $this->tenant->membership();
        if ($membership->user_id !== $actor->id || ! in_array($membership->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true)) {
            throw new AuthorizationException;
        }
    }

    private function photoFor(FaceObservation $observation): Photo
    {
        $photo = Photo::query()
            ->join('face_analysis_runs', function ($join): void {
                $join->on('face_analysis_runs.media_upload_id', '=', 'photos.media_upload_id')
                    ->on('face_analysis_runs.family_space_id', '=', 'photos.family_space_id');
            })
            ->where('face_analysis_runs.id', $observation->face_analysis_run_id)
            ->where('photos.family_space_id', $observation->family_space_id)
            ->select('photos.*')->first();

        return $photo ?? throw ValidationException::withMessages([
            'face_observation' => ['A face can be reviewed only after its MediaUpload is promoted to a Photo.'],
        ]);
    }
}
