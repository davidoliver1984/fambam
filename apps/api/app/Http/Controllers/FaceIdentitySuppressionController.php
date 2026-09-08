<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\FaceRecognition\FaceIdentitySuppressionManager;
use App\Http\Requests\ReopenFaceIdentitySuppressionRequest;
use App\Models\FaceIdentitySuppression;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\User;
use App\Policies\PhotoPolicy;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

class FaceIdentitySuppressionController extends Controller
{
    public function __construct(
        private readonly FaceIdentitySuppressionManager $suppressions,
        private readonly TenantContext $tenant,
        private readonly PhotoPolicy $photos,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        $this->authorizeViewer();
        /** @var User $actor */
        $actor = request()->user();
        $values = FaceIdentitySuppression::query()
            ->where('family_space_id', $familySpace->id)
            ->whereNull('reopened_at')
            ->with(['person', 'observation.run.mediaUpload'])
            ->orderBy('decided_at', 'desc')
            ->get()
            ->map(function (FaceIdentitySuppression $suppression) use ($actor): ?array {
                $photo = Photo::query()->where('family_space_id', $suppression->family_space_id)
                    ->where('media_upload_id', $suppression->observation->run->media_upload_id)->first();
                if ($photo === null || ! $this->photos->view($actor, $photo)) {
                    return null;
                }

                return [
                    'id' => $suppression->id,
                    'person' => [
                        'id' => $suppression->person->id,
                        'preferred_name' => $suppression->person->preferred_name,
                    ],
                    'observation' => [
                        'id' => $suppression->observation->id,
                        'face_index' => $suppression->observation->face_index,
                        'bounds' => [
                            'x' => $suppression->observation->bounds_x,
                            'y' => $suppression->observation->bounds_y,
                            'width' => $suppression->observation->bounds_width,
                            'height' => $suppression->observation->bounds_height,
                        ],
                        'media_upload_id' => $suppression->observation->run->media_upload_id,
                        'image_width' => $suppression->observation->run->mediaUpload->pixel_width,
                        'image_height' => $suppression->observation->run->mediaUpload->pixel_height,
                    ],
                    'decided_at' => $suppression->decided_at->toAtomString(),
                ];
            })->filter()->values();

        return response()->json(['data' => $values]);
    }

    public function reopen(
        FamilySpace $familySpace,
        string $suppression,
        ReopenFaceIdentitySuppressionRequest $request,
    ): JsonResponse {
        $target = FaceIdentitySuppression::query()->where('family_space_id', $familySpace->id)
            ->findOrFail($suppression);
        /** @var User $actor */
        $actor = $request->user();
        $reopened = $this->suppressions->reopen($target, $actor, $request);

        return response()->json(['data' => [
            'id' => $reopened->id,
            'status' => 'reopened',
            'reopened_at' => $reopened->reopened_at?->toAtomString(),
        ]]);
    }

    private function authorizeViewer(): void
    {
        abort_unless(in_array($this->tenant->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true), 403);
    }
}
