<?php

namespace App\Http\Controllers;

use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FamilySpaceRole;
use App\FaceRecognition\FaceIdentityAssignmentManager;
use App\FaceRecognition\FaceIdentitySuppressionManager;
use App\FaceRecognition\FaceSuggestionGenerator;
use App\Http\Requests\ApproveFaceIdentityAssignmentRequest;
use App\Http\Requests\GenerateFaceIdentitySuggestionsRequest;
use App\Http\Requests\RejectFaceIdentityAssignmentRequest;
use App\Http\Requests\StoreFaceIdentityAssignmentRequest;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class FaceIdentityAssignmentController extends Controller
{
    public function __construct(
        private readonly FaceIdentityAssignmentManager $assignments,
        private readonly FaceIdentitySuppressionManager $suppressions,
        private readonly FaceSuggestionGenerator $suggestions,
        private readonly TenantContext $tenant,
        private readonly PhotoPolicy $photos,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        $this->authorizeViewer();
        /** @var User $actor */
        $actor = request()->user();
        $values = FaceIdentityAssignment::query()
            ->where('family_space_id', $familySpace->id)
            ->where('status', FaceIdentityAssignmentStatus::Pending)
            ->with(['person', 'observation.run.mediaUpload'])
            ->orderBy('created_at')
            ->get()
            ->map(fn (FaceIdentityAssignment $assignment): ?array => $this->payloadIfVisible($assignment, $actor))
            ->filter()
            ->values();

        return response()->json(['data' => $values]);
    }

    public function store(
        FamilySpace $familySpace,
        string $faceObservation,
        StoreFaceIdentityAssignmentRequest $request,
    ): JsonResponse {
        $observation = FaceObservation::query()->where('family_space_id', $familySpace->id)
            ->findOrFail($faceObservation);
        $person = Person::query()->where('family_space_id', $familySpace->id)
            ->findOrFail((string) $request->validated('person_id'));
        /** @var User $actor */
        $actor = $request->user();
        $assignment = $this->assignments->propose($observation, $person, $actor, $request);

        return response()->json(['data' => $this->payload($assignment->load(['person', 'observation.run.mediaUpload']))], 201);
    }

    public function suggestions(
        FamilySpace $familySpace,
        string $faceObservation,
        GenerateFaceIdentitySuggestionsRequest $request,
    ): JsonResponse {
        $this->authorizeViewer();
        $observation = FaceObservation::query()->where('family_space_id', $familySpace->id)
            ->with('run')->findOrFail($faceObservation);
        /** @var User $actor */
        $actor = $request->user();
        $photo = $this->photoFor($observation);
        abort_unless($photo !== null && $this->photos->view($actor, $photo), 403);
        if (! (bool) config('face_recognition.processing_enabled')) {
            throw ValidationException::withMessages([
                'recognition_processing_disabled' => [
                    'Recognition suggestions are not enabled for this family yet.',
                ],
            ]);
        }
        $outcome = $this->suggestions->generate(
            TenantOperationContext::forBackground($familySpace->id, $actor->id),
            $observation->id,
        );
        $people = Person::query()->where('family_space_id', $familySpace->id)
            ->whereIn('id', array_map(fn ($candidate): string => $candidate->personId, $outcome->candidates))
            ->get()->keyBy('id');

        return response()->json(['data' => [
            'observation_id' => $observation->id,
            'band' => $outcome->band->value,
            'assignment_id' => $outcome->assignmentId,
            'candidates' => array_values(array_filter(array_map(
                fn ($candidate): ?array => $people->has($candidate->personId) ? [
                    'id' => $candidate->personId,
                    'preferred_name' => $people->get($candidate->personId)->preferred_name,
                ] : null,
                $outcome->candidates,
            ))),
        ]]);
    }

    public function approve(
        FamilySpace $familySpace,
        string $assignment,
        ApproveFaceIdentityAssignmentRequest $request,
    ): JsonResponse {
        $target = FaceIdentityAssignment::query()->where('family_space_id', $familySpace->id)->findOrFail($assignment);
        /** @var User $actor */
        $actor = $request->user();
        $approved = $this->assignments->approve($target, $actor, $request);

        return response()->json(['data' => $this->payload($approved->load(['person', 'observation.run.mediaUpload']))]);
    }

    public function reject(
        FamilySpace $familySpace,
        string $assignment,
        RejectFaceIdentityAssignmentRequest $request,
    ): JsonResponse {
        $target = FaceIdentityAssignment::query()->where('family_space_id', $familySpace->id)->findOrFail($assignment);
        /** @var User $actor */
        $actor = $request->user();
        $suppression = $this->suppressions->rejectAssignment($target, $actor, $request);

        return response()->json(['data' => ['id' => $suppression->id, 'status' => 'suppressed']]);
    }

    private function authorizeViewer(): void
    {
        abort_unless(in_array($this->tenant->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true), 403);
    }

    /** @return array<string, mixed>|null */
    private function payloadIfVisible(FaceIdentityAssignment $assignment, User $actor): ?array
    {
        $photo = $this->photoFor($assignment->observation);

        return $photo !== null && $this->photos->view($actor, $photo)
            ? $this->payload($assignment, $photo)
            : null;
    }

    /** @return array<string, mixed> */
    private function payload(FaceIdentityAssignment $assignment, ?Photo $photo = null): array
    {
        $photo ??= $this->photoFor($assignment->observation);

        return [
            'id' => $assignment->id,
            'status' => $assignment->status->value,
            'proposal_source' => $assignment->proposal_source,
            'person' => [
                'id' => $assignment->person->id,
                'preferred_name' => $assignment->person->preferred_name,
            ],
            'observation' => $this->observationPayload($assignment->observation, $photo),
            'created_at' => $assignment->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function observationPayload(FaceObservation $observation, ?Photo $photo): array
    {
        return [
            'id' => $observation->id,
            'face_index' => $observation->face_index,
            'bounds' => [
                'x' => $observation->bounds_x,
                'y' => $observation->bounds_y,
                'width' => $observation->bounds_width,
                'height' => $observation->bounds_height,
            ],
            'photo_id' => $photo?->id,
            'media_upload_id' => $observation->run->media_upload_id,
            'image_width' => $observation->run->mediaUpload->pixel_width,
            'image_height' => $observation->run->mediaUpload->pixel_height,
        ];
    }

    private function photoFor(FaceObservation $observation): ?Photo
    {
        return Photo::query()->where('family_space_id', $observation->family_space_id)
            ->where('media_upload_id', $observation->run->media_upload_id)->first();
    }
}
