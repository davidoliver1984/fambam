<?php

namespace App\Http\Controllers;

use App\Enums\PersonProposalStatus;
use App\Http\Requests\ReplacePhotoTagsRequest;
use App\Http\Requests\StorePhotoProvenanceRequest;
use App\Http\Requests\StorePhotoRequest;
use App\Http\Requests\UpdatePhotoRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoProvenanceProposal;
use App\Models\User;
use App\Queries\PhotoQuery;
use App\Services\PhotoManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PhotoController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly PhotoManager $photoManager,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Photo::class);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            'data' => $this->photos->listVisibleTo($viewer)->map($this->payload(...)),
        ]);
    }

    public function store(FamilySpace $familySpace, StorePhotoRequest $request): JsonResponse
    {
        Gate::authorize('create', Photo::class);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->photoManager->create(
                $familySpace,
                $actor,
                $request->validated(),
                $request,
            )),
        ], 201);
    }

    public function show(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $target = $this->photos->findVisibleTo($viewer, $photo);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->payload($target)]);
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

    /** @return array<string, mixed> */
    private function payload(Photo $photo): array
    {
        $photo->loadMissing([
            'mediaUpload.uploader:id,name',
            'tags:id,label',
            'photographer:id,preferred_name',
            'scanner:id,preferred_name',
            'physicalOwner:id,preferred_name',
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
            'provenance' => [
                'photographer' => $this->claimPayload($photo->photographer, $photo->photographer_description),
                'scanner' => $this->claimPayload($photo->scanner, $photo->scanner_description),
                'physical_owner' => $this->claimPayload(
                    $photo->physicalOwner,
                    $photo->physical_source_description,
                ),
            ],
            'tags' => $photo->tags->map(fn ($tag): array => ['id' => $tag->id, 'label' => $tag->label])->values(),
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
}
