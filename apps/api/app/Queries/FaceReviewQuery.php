<?php

namespace App\Queries;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Media\MediaDeliveryAuthorization;
use App\Models\FaceAnalysisRun;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\MediaVariant;
use App\Models\Photo;
use App\Models\User;
use App\Services\MediaDeliveryManager;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FaceReviewQuery
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly MediaDeliveryManager $delivery,
        private readonly TenantContext $tenant,
    ) {}

    /**
     * @param  array{upload_batch_id?: string, photo_id?: string, person_id?: string, limit: int, page: int}  $filters
     * @return array<string, mixed>
     */
    public function read(FamilySpace $familySpace, User $viewer, array $filters): array
    {
        $scope = $this->scope($familySpace, $viewer, $filters);
        $perPhoto = $this->perPhoto($scope, $familySpace);
        $summary = DB::query()->fromSub(clone $perPhoto, 'face_review_photos')
            ->selectRaw('COUNT(*) AS total_photos')
            ->selectRaw("SUM(CASE WHEN analysis_state = 'pending' THEN 1 ELSE 0 END) AS pending_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'processing' THEN 1 ELSE 0 END) AS processing_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'succeeded' THEN 1 ELSE 0 END) AS succeeded_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'failed' THEN 1 ELSE 0 END) AS failed_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'succeeded' AND detected_face_count = 0 THEN 1 ELSE 0 END) AS zero_face_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'succeeded' AND detected_face_count > 0 AND remaining_count > 0 THEN 1 ELSE 0 END) AS unresolved_photos")
            ->selectRaw("SUM(CASE WHEN analysis_state = 'succeeded' AND detected_face_count > 0 AND remaining_count = 0 THEN 1 ELSE 0 END) AS resolved_photos")
            ->selectRaw('COALESCE(SUM(detected_face_count), 0) AS total_faces')
            ->selectRaw('COALESCE(SUM(reviewed_count), 0) AS reviewed_count')
            ->selectRaw('COALESCE(SUM(remaining_count), 0) AS remaining_count')
            ->selectRaw('SUM(CASE WHEN remaining_count > 0 THEN 1 ELSE 0 END) AS reviewable_photo_count')
            ->first();
        $navigation = DB::query()->fromSub(clone $perPhoto, 'face_review_navigation')
            ->where('remaining_count', '>', 0)
            ->orderBy('upload_created_at')->orderBy('media_upload_id')->orderBy('photo_id')->limit(2)
            ->pluck('photo_id');

        $limit = $filters['limit'];
        $pageNumber = $filters['page'];
        $photoPage = (clone $scope)->with('mediaUpload')
            ->offset(($pageNumber - 1) * $limit)->limit($limit + 1)->get();
        $hasMore = $photoPage->count() > $limit;
        $photoPage = $photoPage->take($limit)->values();
        $runs = $this->runs($familySpace, $photoPage->pluck('media_upload_id'));
        $variants = $this->variants($familySpace, $photoPage->pluck('media_upload_id'));

        return [
            'scope' => [
                'upload_batch_id' => $filters['upload_batch_id'] ?? null,
                'photo_id' => $filters['photo_id'] ?? null,
                'person_id' => $filters['person_id'] ?? null,
            ],
            'summary' => [
                'total_photos' => (int) ($summary->total_photos ?? 0),
                'analysis' => [
                    'pending' => (int) ($summary->pending_photos ?? 0),
                    'processing' => (int) ($summary->processing_photos ?? 0),
                    'succeeded' => (int) ($summary->succeeded_photos ?? 0),
                    'failed' => (int) ($summary->failed_photos ?? 0),
                    'succeeded_with_zero_faces' => (int) ($summary->zero_face_photos ?? 0),
                    'succeeded_with_unresolved_faces' => (int) ($summary->unresolved_photos ?? 0),
                    'succeeded_with_all_faces_resolved' => (int) ($summary->resolved_photos ?? 0),
                ],
                'total_faces' => (int) ($summary->total_faces ?? 0),
                'reviewed_count' => (int) ($summary->reviewed_count ?? 0),
                'remaining_count' => (int) ($summary->remaining_count ?? 0),
                'reviewable_photo_count' => (int) ($summary->reviewable_photo_count ?? 0),
                'current_photo_id' => $navigation->get(0),
                'next_photo_id' => $navigation->get(1),
            ],
            'photos' => $photoPage->map(fn (Photo $photo): array => $this->photoPayload(
                $familySpace,
                $photo,
                $runs->get($photo->media_upload_id),
                $variants->get($photo->media_upload_id),
                $viewer,
            ))->all(),
            'pagination' => ['page' => $pageNumber, 'limit' => $limit, 'has_more' => $hasMore],
        ];
    }

    /** @param array{upload_batch_id?: string, photo_id?: string, person_id?: string, limit: int, page: int} $filters
     * @return Builder<Photo>
     */
    private function scope(FamilySpace $familySpace, User $viewer, array $filters): Builder
    {
        $query = $this->photos->visibleTo($viewer)->setEagerLoads([])
            ->join('media_uploads', function ($join): void {
                $join->on('media_uploads.id', '=', 'photos.media_upload_id')
                    ->on('media_uploads.family_space_id', '=', 'photos.family_space_id');
            })
            ->select('photos.*')
            ->orderBy('media_uploads.created_at')->orderBy('media_uploads.id')->orderBy('photos.id');
        if (isset($filters['upload_batch_id'])) {
            $query->where('media_uploads.upload_batch_id', $filters['upload_batch_id']);
        }
        if (isset($filters['photo_id'])) {
            $query->where('photos.id', $filters['photo_id']);
        }
        if (isset($filters['person_id'])) {
            $identity = config('image-analysis.identity');
            $query->where('media_uploads.state', MediaUploadState::Ready->value)
                ->whereExists(function ($approved) use ($familySpace, $filters, $identity): void {
                    $approved->selectRaw('1')
                        ->from('face_analysis_runs')
                        ->join('face_observations', function ($join): void {
                            $join->on('face_observations.face_analysis_run_id', '=', 'face_analysis_runs.id')
                                ->on('face_observations.family_space_id', '=', 'face_analysis_runs.family_space_id');
                        })
                        ->join('face_identity_assignments', function ($join) use ($filters): void {
                            $join->on('face_identity_assignments.face_observation_id', '=', 'face_observations.id')
                                ->on('face_identity_assignments.family_space_id', '=', 'face_observations.family_space_id')
                                ->where('face_identity_assignments.person_id', $filters['person_id'])
                                ->where('face_identity_assignments.status', FaceIdentityAssignmentStatus::Approved->value);
                        })
                        ->whereColumn('face_analysis_runs.media_upload_id', 'photos.media_upload_id')
                        ->whereColumn('face_analysis_runs.canonical_sha256', 'media_uploads.canonical_sha256')
                        ->where('face_analysis_runs.family_space_id', $familySpace->id)
                        ->where('face_analysis_runs.provider', $identity['provider'])
                        ->where('face_analysis_runs.model_identifier', $identity['model_identifier'])
                        ->where('face_analysis_runs.model_weight_checksum', $identity['model_weight_checksum'])
                        ->where('face_analysis_runs.config_hash', $identity['config_hash'])
                        ->where('face_analysis_runs.status', FaceAnalysisRunStatus::Succeeded->value);
                });
        }

        return $query;
    }

    /** @param Builder<Photo> $scope */
    private function perPhoto(Builder $scope, FamilySpace $familySpace): QueryBuilder
    {
        $identity = config('image-analysis.identity');
        $base = (clone $scope)->reorder()->select([
            'photos.id as photo_id', 'photos.media_upload_id',
            'media_uploads.created_at as upload_created_at', 'media_uploads.canonical_sha256',
        ]);

        return DB::query()->fromSub($base->toBase(), 'scoped_photos')
            ->leftJoin('face_analysis_runs', function ($join) use ($familySpace, $identity): void {
                $join->on('face_analysis_runs.media_upload_id', '=', 'scoped_photos.media_upload_id')
                    ->on('face_analysis_runs.canonical_sha256', '=', 'scoped_photos.canonical_sha256')
                    ->where('face_analysis_runs.family_space_id', $familySpace->id)
                    ->where('face_analysis_runs.provider', $identity['provider'])
                    ->where('face_analysis_runs.model_identifier', $identity['model_identifier'])
                    ->where('face_analysis_runs.model_weight_checksum', $identity['model_weight_checksum'])
                    ->where('face_analysis_runs.config_hash', $identity['config_hash']);
            })
            ->leftJoin('face_observations', 'face_observations.face_analysis_run_id', '=', 'face_analysis_runs.id')
            ->leftJoin('face_observation_reviews', 'face_observation_reviews.face_observation_id', '=', 'face_observations.id')
            ->leftJoin('face_identity_assignments', function ($join): void {
                $join->on('face_identity_assignments.face_observation_id', '=', 'face_observations.id')
                    ->where(function ($active): void {
                        $active->where('face_identity_assignments.status', FaceIdentityAssignmentStatus::Approved->value)
                            ->orWhere(function ($human): void {
                                $human->where('face_identity_assignments.status', FaceIdentityAssignmentStatus::Pending->value)
                                    ->where('face_identity_assignments.proposal_source', 'human');
                            });
                    });
            })
            ->groupBy([
                'scoped_photos.photo_id', 'scoped_photos.media_upload_id', 'scoped_photos.upload_created_at',
                'face_analysis_runs.id', 'face_analysis_runs.status',
            ])
            ->select(['scoped_photos.photo_id', 'scoped_photos.media_upload_id', 'scoped_photos.upload_created_at'])
            ->selectRaw("COALESCE(face_analysis_runs.status, 'pending') AS analysis_state")
            ->selectRaw('COUNT(DISTINCT face_observations.id) AS detected_face_count')
            ->selectRaw('COUNT(DISTINCT CASE WHEN face_observation_reviews.id IS NOT NULL OR face_identity_assignments.id IS NOT NULL THEN face_observations.id END) AS reviewed_count')
            ->selectRaw('COUNT(DISTINCT face_observations.id) - COUNT(DISTINCT CASE WHEN face_observation_reviews.id IS NOT NULL OR face_identity_assignments.id IS NOT NULL THEN face_observations.id END) AS remaining_count');
    }

    /** @param Collection<int, string> $uploadIds
     * @return Collection<string, FaceAnalysisRun>
     */
    private function runs(FamilySpace $familySpace, Collection $uploadIds): Collection
    {
        if ($uploadIds->isEmpty()) {
            return collect();
        }
        $identity = config('image-analysis.identity');

        return FaceAnalysisRun::query()
            ->join('media_uploads', function ($join): void {
                $join->on('media_uploads.id', '=', 'face_analysis_runs.media_upload_id')
                    ->on('media_uploads.family_space_id', '=', 'face_analysis_runs.family_space_id')
                    ->on('media_uploads.canonical_sha256', '=', 'face_analysis_runs.canonical_sha256');
            })
            ->where('face_analysis_runs.family_space_id', $familySpace->id)
            ->whereIn('face_analysis_runs.media_upload_id', $uploadIds)
            ->where('face_analysis_runs.provider', $identity['provider'])
            ->where('face_analysis_runs.model_identifier', $identity['model_identifier'])
            ->where('face_analysis_runs.model_weight_checksum', $identity['model_weight_checksum'])
            ->where('face_analysis_runs.config_hash', $identity['config_hash'])
            ->with([
                'observations' => fn ($query) => $query->orderBy('face_index')->orderBy('id'),
                'observations.identityAssignments' => fn ($query) => $query
                    ->whereIn('status', [FaceIdentityAssignmentStatus::Pending, FaceIdentityAssignmentStatus::Approved])
                    ->with('person:id,preferred_name')->orderByDesc('created_at'),
                'observations.review',
            ])
            ->select('face_analysis_runs.*')->get()->keyBy('media_upload_id');
    }

    /** @param Collection<int, string> $uploadIds
     * @return Collection<string, MediaVariant>
     */
    private function variants(FamilySpace $familySpace, Collection $uploadIds): Collection
    {
        return MediaVariant::query()->where('family_space_id', $familySpace->id)
            ->whereIn('media_upload_id', $uploadIds)
            ->where('transform_name', MediaVariantTransform::Display->value)
            ->where('processing_version', (int) config('media.processing.variant_processing_version'))
            ->get()->keyBy('media_upload_id');
    }

    /** @return array<string, mixed> */
    private function photoPayload(
        FamilySpace $familySpace,
        Photo $photo,
        ?FaceAnalysisRun $run,
        ?MediaVariant $variant,
        User $viewer,
    ): array {
        $observations = $run instanceof FaceAnalysisRun ? $run->observations : collect();
        $reviewed = $observations->filter(fn (FaceObservation $observation): bool => $this->isReviewed($observation))->count();
        $remaining = $observations->count() - $reviewed;
        try {
            $delivery = $variant === null ? null : $this->delivery->variant($photo->mediaUpload, $variant);
        } catch (NotFoundHttpException) {
            $delivery = null;
        }

        return [
            'photo_id' => $photo->id,
            'upload_batch_id' => $photo->mediaUpload->upload_batch_id,
            'caption' => $photo->caption,
            'display_label' => $photo->caption ?? $photo->mediaUpload->client_filename,
            'media' => $this->mediaPayload($familySpace, $photo, $variant, $delivery),
            'analysis' => [
                'state' => $run?->status->value ?? FaceAnalysisRunStatus::Pending->value,
                'succeeded_with_zero_faces' => $run?->status === FaceAnalysisRunStatus::Succeeded
                    && $observations->isEmpty(),
                'review_state' => $this->analysisReviewState($run, $observations->count(), $remaining),
            ],
            'detected_face_count' => $observations->count(),
            'reviewed_count' => $reviewed,
            'remaining_count' => $remaining,
            'observations' => $observations->map(
                fn (FaceObservation $observation): array => $this->observationPayload($observation, $viewer),
            )->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function mediaPayload(
        FamilySpace $familySpace,
        Photo $photo,
        ?MediaVariant $variant,
        ?MediaDeliveryAuthorization $delivery,
    ): array {
        return [
            'media_upload_id' => $photo->media_upload_id,
            'canonical_width' => $photo->mediaUpload->pixel_width,
            'canonical_height' => $photo->mediaUpload->pixel_height,
            'presentation_width' => $variant?->pixel_width,
            'presentation_height' => $variant?->pixel_height,
            'presentation_url' => $delivery?->url,
            'presentation_expires_at' => $delivery?->expiresAt->toAtomString(),
            'fallback_delivery_endpoint' => "/api/families/{$familySpace->slug}/media-uploads/{$photo->media_upload_id}/canonical",
        ];
    }

    /** @return array<string, mixed> */
    private function observationPayload(FaceObservation $observation, User $viewer): array
    {
        /** @var FaceIdentityAssignment|null $assignment */
        $assignment = $observation->identityAssignments->first();
        $reviewState = $this->reviewState($observation, $assignment);
        $isManager = in_array($this->role($viewer), [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
        ], true);
        $canReview = in_array($this->role($viewer), [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true);
        $assignmentPayload = $assignment === null ? null : [
            'id' => $assignment->id,
            'status' => $assignment->status->value,
            'proposal_source' => $assignment->proposal_source,
            'person' => [
                'id' => $assignment->person->id,
                'preferred_name' => $assignment->person->preferred_name,
            ],
        ];

        return [
            'id' => $observation->id,
            'face_index' => $observation->face_index,
            'bounds' => [
                'x' => $observation->bounds_x,
                'y' => $observation->bounds_y,
                'width' => $observation->bounds_width,
                'height' => $observation->bounds_height,
            ],
            'review_state' => $reviewState,
            'reviewed' => in_array($reviewState, ['human_proposal', 'approved_identity', 'left_unidentified'], true),
            'suggested_people' => $reviewState === 'automatic_suggestion' && $assignmentPayload !== null
                ? [$assignmentPayload['person']]
                : [],
            'current_proposal' => $reviewState === 'human_proposal' ? $assignmentPayload : null,
            'current_identity' => $reviewState === 'approved_identity' ? $assignmentPayload : null,
            'identity_assignment' => $assignmentPayload,
            'permissions' => [
                'can_assign' => $canReview && $reviewState !== 'approved_identity',
                'can_change' => $canReview && ($reviewState !== 'approved_identity' || $isManager),
                'can_leave_unidentified' => $canReview && $reviewState !== 'approved_identity',
                'can_approve' => $isManager && in_array($reviewState, ['automatic_suggestion', 'human_proposal'], true),
                'can_reject' => $isManager && in_array($reviewState, ['automatic_suggestion', 'human_proposal'], true),
            ],
        ];
    }

    private function isReviewed(FaceObservation $observation): bool
    {
        return in_array(
            $this->reviewState($observation, $observation->identityAssignments->first()),
            ['human_proposal', 'approved_identity', 'left_unidentified'],
            true,
        );
    }

    private function reviewState(FaceObservation $observation, ?FaceIdentityAssignment $assignment): string
    {
        if ($assignment?->status === FaceIdentityAssignmentStatus::Approved
            || ($assignment?->status === FaceIdentityAssignmentStatus::Pending
                && $assignment->proposal_source === 'human')) {
            return $assignment->status === FaceIdentityAssignmentStatus::Approved
                ? 'approved_identity'
                : 'human_proposal';
        }

        if ($assignment?->status === FaceIdentityAssignmentStatus::Pending) {
            return 'automatic_suggestion';
        }

        return $observation->review === null ? 'unreviewed' : 'left_unidentified';
    }

    private function analysisReviewState(?FaceAnalysisRun $run, int $detectedFaces, int $remaining): string
    {
        if ($run === null || $run->status === FaceAnalysisRunStatus::Pending) {
            return 'pending';
        }
        if ($run->status === FaceAnalysisRunStatus::Processing) {
            return 'processing';
        }
        if ($run->status === FaceAnalysisRunStatus::Failed) {
            return 'failed';
        }
        if ($detectedFaces === 0) {
            return 'succeeded_with_zero_faces';
        }

        return $remaining > 0
            ? 'succeeded_with_unresolved_faces'
            : 'succeeded_with_all_faces_resolved';
    }

    private function role(User $viewer): FamilySpaceRole
    {
        $membership = $this->tenant->membership();
        abort_unless($membership->user_id === $viewer->id, 403);

        return $membership->role;
    }
}
