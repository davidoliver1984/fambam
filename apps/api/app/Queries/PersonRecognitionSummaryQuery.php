<?php

namespace App\Queries;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\MediaUploadState;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

final class PersonRecognitionSummaryQuery
{
    public function __construct(private readonly PhotoQuery $photos) {}

    /** @return array{recognised_photo_count: int, viewer_identification_count: int, review_destination: array{path: string, filter: array{person_id: string}}|null} */
    public function forPerson(FamilySpace $familySpace, Person $person, User $viewer): array
    {
        $approvedPhotos = $this->approvedPhotos($familySpace, $person, $viewer);
        $recognisedPhotoCount = (clone $approvedPhotos)->distinct()->count('photos.id');
        $viewerIdentificationCount = (clone $approvedPhotos)
            ->where(function (Builder $contribution) use ($viewer): void {
                $contribution->where('face_identity_assignments.proposed_by', $viewer->id)
                    ->orWhere('face_identity_assignments.resolved_by', $viewer->id);
            })
            ->distinct()
            ->count('photos.id');
        $mayReview = Gate::allows('viewAny', Photo::class)
            && Gate::allows('viewAny', Person::class);

        return [
            'recognised_photo_count' => $recognisedPhotoCount,
            'viewer_identification_count' => $viewerIdentificationCount,
            'review_destination' => $mayReview ? [
                'path' => "/families/{$familySpace->slug}/photos/review-people",
                'filter' => ['person_id' => $person->id],
            ] : null,
        ];
    }

    /** @return Builder<Photo> */
    private function approvedPhotos(FamilySpace $familySpace, Person $person, User $viewer): Builder
    {
        $identity = config('image-analysis.identity');

        return $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->join('media_uploads', function ($join): void {
                $join->on('media_uploads.id', '=', 'photos.media_upload_id')
                    ->on('media_uploads.family_space_id', '=', 'photos.family_space_id');
            })
            ->join('face_analysis_runs', function ($join) use ($familySpace, $identity): void {
                $join->on('face_analysis_runs.media_upload_id', '=', 'photos.media_upload_id')
                    ->on('face_analysis_runs.family_space_id', '=', 'photos.family_space_id')
                    ->on('face_analysis_runs.canonical_sha256', '=', 'media_uploads.canonical_sha256')
                    ->where('face_analysis_runs.family_space_id', $familySpace->id)
                    ->where('face_analysis_runs.provider', $identity['provider'])
                    ->where('face_analysis_runs.model_identifier', $identity['model_identifier'])
                    ->where('face_analysis_runs.model_weight_checksum', $identity['model_weight_checksum'])
                    ->where('face_analysis_runs.config_hash', $identity['config_hash'])
                    ->where('face_analysis_runs.status', FaceAnalysisRunStatus::Succeeded->value);
            })
            ->join('face_observations', function ($join): void {
                $join->on('face_observations.face_analysis_run_id', '=', 'face_analysis_runs.id')
                    ->on('face_observations.family_space_id', '=', 'photos.family_space_id');
            })
            ->join('face_identity_assignments', function ($join) use ($person): void {
                $join->on('face_identity_assignments.face_observation_id', '=', 'face_observations.id')
                    ->on('face_identity_assignments.family_space_id', '=', 'photos.family_space_id')
                    ->where('face_identity_assignments.person_id', $person->id)
                    ->where('face_identity_assignments.status', FaceIdentityAssignmentStatus::Approved->value);
            })
            ->where('media_uploads.state', MediaUploadState::Ready->value);
    }
}
