<?php

namespace App\Http\Controllers;

use App\Enums\PersonProposalStatus;
use App\Http\Requests\ReplacePhotoTagsRequest;
use App\Http\Requests\StorePhotoMetadataProposalRequest;
use App\Http\Requests\StorePhotoPersonRequest;
use App\Http\Requests\StorePhotoProvenanceRequest;
use App\Http\Requests\StorePhotoRequest;
use App\Http\Requests\UpdatePhotoRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
use App\Models\PhotoProvenanceProposal;
use App\Models\User;
use App\People\UncertainDate;
use App\Queries\PhotoQuery;
use App\Services\PhotoDeletionManager;
use App\Services\PhotoManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PhotoController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly PhotoManager $photoManager,
        private readonly PhotoDeletionManager $deletionManager,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Photo::class);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            'data' => $this->photos->listVisibleTo($viewer, $request->validate([
                'person_id' => ['sometimes', 'string', 'size:26'],
                'tag' => ['sometimes', 'string', 'max:80'],
                'location' => ['sometimes', 'string', 'max:255'],
                'historical_year' => ['sometimes', 'integer', 'between:1,9999'],
                'without_confirmed_date' => ['sometimes', 'boolean'],
            ]))->map($this->payload(...)),
        ]);
    }

    public function store(FamilySpace $familySpace, StorePhotoRequest $request): JsonResponse
    {
        Gate::authorize('create', Photo::class);
        /** @var User $actor */
        $actor = $request->user();

        $result = $this->photoManager->create(
            $familySpace,
            $actor,
            $request->validated(),
            $request,
        );

        if ($result->outcome === 'duplicate_detected') {
            return response()->json(['data' => [
                'outcome' => $result->outcome,
                'candidates' => $result->candidates->map($this->duplicateCandidatePayload(...))->values(),
            ]], 409);
        }
        if ($result->outcome === 'cancelled') {
            return response()->json(null, 204);
        }

        return response()->json([
            'data' => [
                'outcome' => $result->outcome,
                'photo' => $this->payload($result->photo ?? throw new \LogicException('Photo result missing.')),
            ],
        ], $result->outcome === 'photo_created' ? 201 : 200);
    }

    public function show(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $target = $this->photos->findVisibleTo($viewer, $photo);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->payload($target)]);
    }

    public function deleted(FamilySpace $familySpace, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['data' => $this->photos->deletedManageableBy($viewer)->map(
            fn (Photo $photo): array => ['id' => $photo->id, 'client_filename' => $photo->mediaUpload->client_filename,
                'caption' => $photo->caption, 'deleted_at' => $photo->deleted_at?->toAtomString(),
                'permissions' => ['can_restore' => Gate::allows('restore', $photo)]],
        )]);
    }

    public function destroy(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('delete', $target);
        $this->deletionManager->delete($target, $actor, $request);

        return response()->json(null, 204);
    }

    public function restore(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findDeletedManageableBy($actor, $photo);
        Gate::authorize('restore', $target);

        return response()->json(['data' => $this->payload($this->deletionManager->restore($target, $actor, $request))]);
    }

    public function update(
        FamilySpace $familySpace,
        string $photo,
        UpdatePhotoRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('update', $target);

        return response()->json([
            'data' => $this->payload($this->photoManager->update(
                $target,
                $actor,
                $request->validated(),
                $request,
            )),
        ]);
    }

    public function replaceTags(
        FamilySpace $familySpace,
        string $photo,
        ReplacePhotoTagsRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('manageTags', $target);
        /** @var list<string> $labels */
        $labels = $request->validated('tags');

        return response()->json([
            'data' => $this->payload($this->photoManager->replaceTags(
                $target,
                $actor,
                $labels,
                $request,
            )),
        ]);
    }

    public function submitProvenance(
        FamilySpace $familySpace,
        string $photo,
        StorePhotoProvenanceRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('proposeProvenance', $target);

        $proposal = $this->photoManager->submitProvenance(
            $target,
            $actor,
            $request->validated(),
            $request,
        );

        return response()->json(['data' => $this->proposalPayload($proposal)], 201);
    }

    public function proposals(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('resolveProvenance', $target);

        return response()->json([
            'data' => $this->photos->pendingProposals($target)->map($this->proposalPayload(...)),
        ]);
    }

    public function approveProposal(
        FamilySpace $familySpace,
        string $photo,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolveProposal($photo, $proposal, PersonProposalStatus::Approved, $request);
    }

    public function rejectProposal(
        FamilySpace $familySpace,
        string $photo,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolveProposal($photo, $proposal, PersonProposalStatus::Rejected, $request);
    }

    private function resolveProposal(
        string $photoId,
        string $proposalId,
        PersonProposalStatus $resolution,
        Request $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $photo = $this->photos->findVisibleTo($actor, $photoId);
        Gate::authorize('resolveProvenance', $photo);
        $proposal = $this->photos->findProposal($photo, $proposalId);

        return response()->json(['data' => $this->proposalPayload(
            $this->photoManager->resolveProvenance($photo, $proposal, $actor, $resolution, $request),
        )]);
    }

    public function submitMetadata(
        FamilySpace $familySpace,
        string $photo,
        StorePhotoMetadataProposalRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('proposeProvenance', $target);

        return response()->json(['data' => $this->metadataProposalPayload(
            $this->photoManager->submitMetadata($target, $actor, $request->validated(), $request),
        )], 201);
    }

    public function metadataProposals(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('resolveProvenance', $target);

        return response()->json(['data' => $this->photos->pendingMetadataProposals($target)
            ->map($this->metadataProposalPayload(...))]);
    }

    public function approveMetadata(FamilySpace $familySpace, string $photo, string $proposal, Request $request): JsonResponse
    {
        return $this->resolveMetadataProposal($photo, $proposal, PersonProposalStatus::Approved, $request);
    }

    public function rejectMetadata(FamilySpace $familySpace, string $photo, string $proposal, Request $request): JsonResponse
    {
        return $this->resolveMetadataProposal($photo, $proposal, PersonProposalStatus::Rejected, $request);
    }

    private function resolveMetadataProposal(
        string $photoId,
        string $proposalId,
        PersonProposalStatus $resolution,
        Request $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $photo = $this->photos->findVisibleTo($actor, $photoId);
        Gate::authorize('resolveProvenance', $photo);
        $proposal = $this->photos->findMetadataProposal($photo, $proposalId);

        return response()->json(['data' => $this->metadataProposalPayload(
            $this->photoManager->resolveMetadata($photo, $proposal, $actor, $resolution, $request),
        )]);
    }

    public function submitPhotoPerson(
        FamilySpace $familySpace,
        string $photo,
        StorePhotoPersonRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('proposeProvenance', $target);

        return response()->json(['data' => $this->photoPersonPayload(
            $this->photoManager->submitPhotoPerson($target, $actor, $request->validated(), $request),
        )], 201);
    }

    public function photoPersonProposals(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->photos->findVisibleTo($actor, $photo);
        Gate::authorize('resolveProvenance', $target);

        return response()->json(['data' => $this->photos->pendingPhotoPeople($target)
            ->map($this->photoPersonPayload(...))]);
    }

    public function approvePhotoPerson(FamilySpace $familySpace, string $photo, string $association, Request $request): JsonResponse
    {
        return $this->resolvePhotoPersonProposal($photo, $association, PersonProposalStatus::Approved, $request);
    }

    public function rejectPhotoPerson(FamilySpace $familySpace, string $photo, string $association, Request $request): JsonResponse
    {
        return $this->resolvePhotoPersonProposal($photo, $association, PersonProposalStatus::Rejected, $request);
    }

    private function resolvePhotoPersonProposal(
        string $photoId,
        string $associationId,
        PersonProposalStatus $resolution,
        Request $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $photo = $this->photos->findVisibleTo($actor, $photoId);
        Gate::authorize('resolveProvenance', $photo);
        $association = $this->photos->findPhotoPerson($photo, $associationId);

        return response()->json(['data' => $this->photoPersonPayload(
            $this->photoManager->resolvePhotoPerson($photo, $association, $actor, $resolution, $request),
        )]);
    }

    /** @return array<string, mixed> */
    private function payload(Photo $photo): array
    {
        $photo->loadMissing([
            'mediaUpload.uploader:id,name',
            'tags:id,label',
            'photographer:id,preferred_name',
            'scanner:id,preferred_name',
            'physicalOwner:id,preferred_name',
            'primaryEvent:id,name,starts_on',
            'photoPeople' => fn ($query) => $query
                ->where('status', 'approved')
                ->with('person:id,preferred_name'),
        ]);

        return [
            'id' => $photo->id,
            'media_upload' => [
                'id' => $photo->media_upload_id,
                'client_filename' => $photo->mediaUpload->client_filename,
                'uploader' => $photo->mediaUpload->uploader === null ? null : [
                    'id' => $photo->mediaUpload->uploader->id,
                    'name' => $photo->mediaUpload->uploader->name,
                ],
            ],
            'created_by' => $photo->created_by,
            'visibility' => $photo->visibility->value,
            'caption' => $photo->caption,
            'description' => $photo->description,
            'archive_source_description' => $photo->archive_source_description,
            'primary_event_id' => $photo->primary_event_id,
            'primary_event' => $photo->primaryEvent === null ? null : [
                'id' => $photo->primaryEvent->id,
                'name' => $photo->primaryEvent->name,
                'starts_on' => $photo->primaryEvent->starts_on?->format('Y-m-d'),
            ],
            'historical_date' => $photo->historical_date_precision === null ? null : UncertainDate::fromStorage(
                $photo->historical_date_precision,
                $photo->historical_date?->format('Y-m-d'),
            )->toPayload(),
            'location_description' => $photo->location_description,
            'provenance' => [
                'photographer' => $this->claimPayload($photo->photographer, $photo->photographer_description),
                'scanner' => $this->claimPayload($photo->scanner, $photo->scanner_description),
                'physical_owner' => $this->claimPayload(
                    $photo->physicalOwner,
                    $photo->physical_source_description,
                ),
            ],
            'tags' => $photo->tags->map(fn ($tag): array => ['id' => $tag->id, 'label' => $tag->label])->values(),
            'people' => $photo->photoPeople->map($this->photoPersonPayload(...))->values(),
            'created_at' => $photo->created_at?->toAtomString(),
            'updated_at' => $photo->updated_at?->toAtomString(),
            'permissions' => [
                'can_update' => Gate::allows('update', $photo),
                'can_propose_provenance' => Gate::allows('proposeProvenance', $photo),
                'can_resolve_provenance' => Gate::allows('resolveProvenance', $photo),
                'can_manage_tags' => Gate::allows('manageTags', $photo),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function duplicateCandidatePayload(Photo $photo): array
    {
        $photo->loadMissing('mediaUpload');

        return [
            'id' => $photo->id,
            'caption' => $photo->caption,
            'visibility' => $photo->visibility->value,
            'client_filename' => $photo->mediaUpload->client_filename,
            'created_at' => $photo->created_at?->toAtomString(),
        ];
    }

    /** @return array{person: array{id: string, preferred_name: string}|null, description: string|null} */
    private function claimPayload(?Person $person, ?string $description): array
    {
        return [
            'person' => $person === null ? null : [
                'id' => (string) $person->id,
                'preferred_name' => (string) $person->preferred_name,
            ],
            'description' => $description,
        ];
    }

    /** @return array<string, mixed> */
    private function proposalPayload(PhotoProvenanceProposal $proposal): array
    {
        $proposal->loadMissing('person:id,preferred_name');

        return [
            'id' => $proposal->id,
            'photo_id' => $proposal->photo_id,
            'role' => $proposal->role->value,
            'person' => $proposal->person === null ? null : [
                'id' => $proposal->person->id,
                'preferred_name' => $proposal->person->preferred_name,
            ],
            'description' => $proposal->description,
            'clears_claim' => $proposal->clears_claim,
            'status' => $proposal->status->value,
            'proposed_by' => $proposal->proposed_by,
            'resolved_by' => $proposal->resolved_by,
            'resolved_at' => $proposal->resolved_at?->toAtomString(),
            'created_at' => $proposal->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function metadataProposalPayload(PhotoMetadataProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'photo_id' => $proposal->photo_id,
            'field' => $proposal->field->value,
            'date' => $proposal->date_precision === null ? null : UncertainDate::fromStorage(
                $proposal->date_precision,
                $proposal->date_value?->format('Y-m-d'),
            )->toPayload(),
            'location_description' => $proposal->location_description,
            'clears_claim' => $proposal->clears_claim,
            'status' => $proposal->status->value,
            'proposed_by' => $proposal->proposed_by,
            'resolved_by' => $proposal->resolved_by,
            'resolved_at' => $proposal->resolved_at?->toAtomString(),
            'created_at' => $proposal->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function photoPersonPayload(PhotoPerson $association): array
    {
        $association->loadMissing('person:id,preferred_name');

        return [
            'id' => $association->id,
            'photo_id' => $association->photo_id,
            'person' => [
                'id' => $association->person->id,
                'preferred_name' => $association->person->preferred_name,
            ],
            'proposal_source' => $association->proposal_source,
            'status' => $association->status->value,
            'proposed_by' => $association->proposed_by,
            'resolved_by' => $association->resolved_by,
            'resolved_at' => $association->resolved_at?->toAtomString(),
            'created_at' => $association->created_at?->toAtomString(),
        ];
    }
}
