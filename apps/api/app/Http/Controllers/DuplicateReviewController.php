<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\FlagPhotoDuplicateRequest;
use App\Models\DuplicateCandidate;
use App\Models\DuplicateDecision;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\User;
use App\Services\DuplicateReviewManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DuplicateReviewController extends Controller
{
    public function __construct(
        private readonly DuplicateReviewManager $reviews,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        $this->authorizeReviewer();

        return response()->json(['data' => $this->reviews->pending($familySpace->id)
            ->map(fn (DuplicateCandidate $candidate): array => $this->candidatePayload($candidate))]);
    }

    public function decisions(FamilySpace $familySpace): JsonResponse
    {
        $this->authorizeReviewer();

        return response()->json(['data' => $this->reviews->settled($familySpace->id)
            ->map(fn (DuplicateDecision $decision): array => $this->decisionPayload($decision))]);
    }

    public function flag(
        FamilySpace $familySpace,
        string $photo,
        FlagPhotoDuplicateRequest $request,
    ): JsonResponse {
        abort_unless(in_array($this->tenantContext->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true), 403);
        $source = Photo::query()->where('family_space_id', $familySpace->id)->findOrFail($photo);
        $candidate = Photo::query()->where('family_space_id', $familySpace->id)
            ->findOrFail((string) $request->validated('candidate_photo_id'));
        Gate::authorize('view', $source);
        Gate::authorize('view', $candidate);
        /** @var User $actor */
        $actor = $request->user();
        $record = $this->reviews->flag($source, $candidate, $actor, $request);

        return response()->json(['data' => ['id' => $record->id, 'status' => $record->status]], 201);
    }

    public function dismiss(
        FamilySpace $familySpace,
        DuplicateCandidate $candidate,
        Request $request,
    ): JsonResponse {
        $this->authorizeReviewer();
        abort_unless($candidate->family_space_id === $familySpace->id, 404);
        /** @var User $actor */
        $actor = $request->user();
        $decision = $this->reviews->dismiss($candidate, $actor, $request);

        return response()->json(['data' => $this->decisionPayload(
            $decision->load(['lowPhoto.mediaUpload', 'highPhoto.mediaUpload']),
        )]);
    }

    public function reopen(
        FamilySpace $familySpace,
        DuplicateDecision $decision,
        Request $request,
    ): JsonResponse {
        $this->authorizeReviewer();
        abort_unless($decision->family_space_id === $familySpace->id, 404);
        /** @var User $actor */
        $actor = $request->user();
        $this->reviews->reopen($decision, $actor, $request);

        return response()->json(['data' => ['id' => $decision->id, 'status' => 'reopened']]);
    }

    private function authorizeReviewer(): void
    {
        abort_unless($this->tenantContext->membership()->role->canManageMembers(), 403);
    }

    /** @return array<string, mixed> */
    private function candidatePayload(DuplicateCandidate $candidate): array
    {
        return [
            'id' => $candidate->id,
            'source' => $candidate->source,
            'algorithm' => $candidate->algorithm,
            'processing_version' => $candidate->processing_version,
            'score' => $candidate->score === null ? null : (float) $candidate->score,
            'photo' => $this->photoPayload($candidate->photo),
            'candidate_photo' => $this->photoPayload($candidate->candidatePhoto),
            'created_at' => $candidate->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function decisionPayload(DuplicateDecision $decision): array
    {
        return [
            'id' => $decision->id,
            'source' => $decision->source,
            'photo' => $this->photoPayload($decision->lowPhoto),
            'candidate_photo' => $this->photoPayload($decision->highPhoto),
            'decided_at' => $decision->decided_at->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function photoPayload(Photo $photo): array
    {
        $photo->loadMissing('mediaUpload');

        return [
            'id' => $photo->id,
            'media_upload_id' => $photo->media_upload_id,
            'caption' => $photo->caption,
            'client_filename' => $photo->mediaUpload->client_filename,
            'visibility' => $photo->visibility->value,
            'created_at' => $photo->created_at?->toAtomString(),
        ];
    }
}
