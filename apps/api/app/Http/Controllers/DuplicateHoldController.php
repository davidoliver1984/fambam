<?php

namespace App\Http\Controllers;

use App\Http\Requests\ResolveDuplicateHoldRequest;
use App\Models\FamilySpace;
use App\Models\MediaUploadDuplicateHold;
use App\Models\Photo;
use App\Services\DuplicateHoldManager;
use App\Services\ExactDuplicateDetector;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DuplicateHoldController extends Controller
{
    public function __construct(
        private readonly ExactDuplicateDetector $duplicates,
        private readonly DuplicateHoldManager $manager,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $holds = MediaUploadDuplicateHold::query()
            ->with(['mediaUpload', 'targetAlbum'])
            ->whereHas('mediaUpload', fn ($query) => $query->where('user_id', $request->user()->id))
            ->whereNull('resolved_at')
            ->oldest('detected_at')
            ->get();

        return response()->json(['data' => $holds->map(
            fn (MediaUploadDuplicateHold $hold): array => $this->payload($hold, $request),
        )]);
    }

    public function resolve(
        FamilySpace $familySpace,
        string $hold,
        ResolveDuplicateHoldRequest $request,
    ): JsonResponse {
        $target = MediaUploadDuplicateHold::query()
            ->with(['mediaUpload', 'targetAlbum'])
            ->whereHas('mediaUpload', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($hold);
        $photo = $this->manager->resolve($target, $request->user(), $request->validated(), $request);

        return response()->json(['data' => [
            'outcome' => $request->validated('resolution'),
            'photo_id' => $photo?->id,
        ]]);
    }

    /** @return array<string, mixed> */
    private function payload(MediaUploadDuplicateHold $hold, Request $request): array
    {
        $matches = $this->duplicates->visibleMatches(
            $hold->mediaUpload,
            $request->user(),
            $this->tenantContext->membership(),
        );

        return [
            'id' => $hold->id,
            'media_upload' => [
                'id' => $hold->media_upload_id,
                'client_filename' => $hold->mediaUpload->client_filename,
            ],
            'target_album' => [
                'id' => $hold->target_album_id,
                'name' => $hold->targetAlbum->name,
                'visibility' => $hold->targetAlbum->visibility->value,
            ],
            'detected_at' => $hold->detected_at->toAtomString(),
            'candidates' => $matches->map(fn (Photo $photo): array => [
                'id' => $photo->id,
                'caption' => $photo->caption,
                'visibility' => $photo->visibility->value,
                'client_filename' => $photo->mediaUpload->client_filename,
                'created_at' => $photo->created_at?->toAtomString(),
            ])->values(),
        ];
    }
}
