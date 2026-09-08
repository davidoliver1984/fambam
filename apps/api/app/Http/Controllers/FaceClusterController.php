<?php

namespace App\Http\Controllers;

use App\Enums\FaceClusterGenerationStatus;
use App\Enums\FaceClusterStatus;
use App\Enums\FamilySpaceRole;
use App\FaceRecognition\FaceClusterReviewManager;
use App\Http\Requests\MergeFaceClustersRequest;
use App\Http\Requests\NameFaceClusterRequest;
use App\Http\Requests\SplitFaceClusterRequest;
use App\Models\FaceCluster;
use App\Models\FaceClusterGeneration;
use App\Models\FaceClusterMember;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class FaceClusterController extends Controller
{
    public function __construct(
        private readonly FaceClusterReviewManager $clusters,
        private readonly TenantContext $tenant,
        private readonly PhotoPolicy $photos,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        $this->authorizeViewer();
        $generation = FaceClusterGeneration::query()
            ->where('family_space_id', $familySpace->id)
            ->where('status', FaceClusterGenerationStatus::Active)
            ->first();
        if ($generation === null) {
            return response()->json([
                'data' => [],
                'recognition_processing_enabled' => (bool) config('face_recognition.processing_enabled'),
            ]);
        }
        /** @var User $actor */
        $actor = request()->user();
        $values = FaceCluster::query()
            ->where('family_space_id', $familySpace->id)
            ->where('clustering_generation_id', $generation->id)
            ->where('status', FaceClusterStatus::Active)
            ->with(['members' => fn ($query) => $query->where('is_active', true)
                ->with('observation.run.mediaUpload')->orderBy('face_observation_id')])
            ->orderBy('id')
            ->get()
            ->filter(fn (FaceCluster $cluster): bool => $cluster->members->every(
                function (FaceClusterMember $member) use ($actor): bool {
                    $photo = Photo::query()->where('family_space_id', $member->family_space_id)
                        ->where('media_upload_id', $member->observation->run->media_upload_id)->first();

                    return $photo !== null && $this->photos->view($actor, $photo);
                },
            ))
            ->map($this->payload(...))
            ->values();

        return response()->json([
            'data' => $values,
            'recognition_processing_enabled' => (bool) config('face_recognition.processing_enabled'),
        ]);
    }

    public function name(
        FamilySpace $familySpace,
        string $cluster,
        NameFaceClusterRequest $request,
    ): JsonResponse {
        $target = FaceCluster::query()->where('family_space_id', $familySpace->id)->findOrFail($cluster);
        $person = Person::query()->where('family_space_id', $familySpace->id)
            ->findOrFail((string) $request->validated('person_id'));
        /** @var User $actor */
        $actor = $request->user();
        $confirmed = (bool) $request->validated('confirm');
        if ($confirmed) {
            abort_unless($this->tenant->membership()->role->canManageMembers(), 403);
        }
        $count = $confirmed
            ? $this->clusters->confirmName($target, $person, $actor, $request)
            : $this->clusters->proposeName($target, $person, $actor, $request);

        return response()->json(['data' => [
            'cluster_id' => $target->id,
            'assignment_count' => $count,
            'status' => $confirmed ? 'confirmed' : 'proposed',
        ]]);
    }

    public function merge(FamilySpace $familySpace, MergeFaceClustersRequest $request): JsonResponse
    {
        $clusterIds = $request->validated('cluster_ids');
        $targets = FaceCluster::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', $clusterIds)->orderBy('id')->get()->all();
        /** @var User $actor */
        $actor = $request->user();
        $merged = $this->clusters->merge($targets, $actor, $request);

        return response()->json(['data' => $this->payload($merged->load([
            'members' => fn ($query) => $query->where('is_active', true)->with('observation.run.mediaUpload'),
        ]))], 201);
    }

    public function split(
        FamilySpace $familySpace,
        string $cluster,
        SplitFaceClusterRequest $request,
    ): JsonResponse {
        $target = FaceCluster::query()->where('family_space_id', $familySpace->id)->findOrFail($cluster);
        /** @var User $actor */
        $actor = $request->user();
        $replacements = $this->clusters->split($target, $request->validated('groups'), $actor, $request);

        return response()->json(['data' => array_map(fn (FaceCluster $replacement): array => $this->payload(
            $replacement->load(['members' => fn ($query) => $query->where('is_active', true)->with('observation.run.mediaUpload')]),
        ), $replacements)], 201);
    }

    private function authorizeViewer(): void
    {
        abort_unless(in_array($this->tenant->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true), 403);
    }

    /** @return array<string, mixed> */
    private function payload(FaceCluster $cluster): array
    {
        return [
            'id' => $cluster->id,
            'generation_id' => $cluster->clustering_generation_id,
            'status' => $cluster->status->value,
            'members' => $cluster->members->map(fn (FaceClusterMember $member): array => [
                'id' => $member->id,
                'observation' => [
                    'id' => $member->observation->id,
                    'face_index' => $member->observation->face_index,
                    'bounds' => [
                        'x' => $member->observation->bounds_x,
                        'y' => $member->observation->bounds_y,
                        'width' => $member->observation->bounds_width,
                        'height' => $member->observation->bounds_height,
                    ],
                    'media_upload_id' => $member->observation->run->media_upload_id,
                    'image_width' => $member->observation->run->mediaUpload->pixel_width,
                    'image_height' => $member->observation->run->mediaUpload->pixel_height,
                ],
            ])->values(),
        ];
    }
}
