<?php

namespace App\Http\Controllers;

use App\FaceRecognition\FaceObservationReviewManager;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\FaceReviewQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FaceReviewController extends Controller
{
    public function __construct(
        private readonly FaceReviewQuery $reviews,
        private readonly FaceObservationReviewManager $reviewManager,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Photo::class);
        Gate::authorize('viewAny', Person::class);
        $validated = $request->validate([
            'upload_batch_id' => ['sometimes', 'ulid'],
            'photo_id' => ['sometimes', 'ulid'],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);
        /** @var User $viewer */
        $viewer = $request->user();
        $data = $this->reviews->read($familySpace, $viewer, [
            ...$validated,
            'limit' => (int) ($validated['limit'] ?? 50),
            'page' => (int) ($validated['page'] ?? 1),
        ]);
        if (isset($validated['photo_id']) && $data['summary']['total_photos'] === 0) {
            abort(404);
        }

        return response()->json(['data' => $data]);
    }

    public function leaveUnidentified(
        FamilySpace $familySpace,
        string $faceObservation,
        Request $request,
    ): JsonResponse {
        $observation = FaceObservation::query()
            ->where('family_space_id', $familySpace->id)
            ->findOrFail($faceObservation);
        /** @var User $actor */
        $actor = $request->user();
        $review = $this->reviewManager->leaveUnidentified($observation, $actor, $request);

        return response()->json(['data' => [
            'observation_id' => $review->face_observation_id,
            'review_state' => $review->disposition->value,
            'reviewed_at' => $review->reviewed_at->toAtomString(),
        ]]);
    }
}
