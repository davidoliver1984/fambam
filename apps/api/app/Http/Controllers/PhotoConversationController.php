<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoReactionRequest;
use App\Http\Requests\StorePhotoTextRequest;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Queries\PhotoQuery;
use App\Services\PhotoConversationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PhotoConversationController extends Controller
{
    public function __construct(private readonly PhotoQuery $photos, private readonly PhotoConversationManager $manager) {}

    public function index(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);

        return response()->json(['data' => $this->payload($target)]);
    }

    public function storeStory(FamilySpace $familySpace, string $photo, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        Gate::authorize('interact', $target);

        return response()->json(['data' => $this->textPayload($this->manager->createStory($target, $request->user(), $request->validated('body'), $request))], 201);
    }

    public function updateStory(FamilySpace $familySpace, string $photo, string $story, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoStory::query()->where('photo_id', $target->id)->findOrFail($story);
        Gate::authorize('update', $model);

        return response()->json(['data' => $this->textPayload($this->manager->updateStory($model, $request->user(), $request->validated('body'), $request))]);
    }

    public function removeStory(FamilySpace $familySpace, string $photo, string $story, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoStory::query()->where('photo_id', $target->id)->findOrFail($story);
        Gate::authorize('delete', $model);
        $this->manager->remove($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function storeComment(FamilySpace $familySpace, string $photo, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        Gate::authorize('interact', $target);

        return response()->json(['data' => $this->textPayload($this->manager->createComment($target, $request->user(), $request->validated('body'), $request))], 201);
    }

    public function updateComment(FamilySpace $familySpace, string $photo, string $comment, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoComment::query()->where('photo_id', $target->id)->findOrFail($comment);
        Gate::authorize('update', $model);

        return response()->json(['data' => $this->textPayload($this->manager->updateComment($model, $request->user(), $request->validated('body'), $request))]);
    }

    public function removeComment(FamilySpace $familySpace, string $photo, string $comment, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoComment::query()->where('photo_id', $target->id)->findOrFail($comment);
        Gate::authorize('delete', $model);
        $this->manager->remove($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function react(FamilySpace $familySpace, string $photo, StorePhotoReactionRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        Gate::authorize('interact', $target);

        return response()->json(['data' => $this->manager->react($target, $request->user(), $request->validated('reaction'), $request)]);
    }

    public function removeReaction(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        Gate::authorize('interact', $target);
        $this->manager->removeReaction($target, $request->user(), $request);

        return response()->json(null, 204);
    }

    private function photo(Request $request, string $id): Photo
    {
        $photo = $this->photos->findVisibleTo($request->user(), $id);
        Gate::authorize('view', $photo);

        return $photo;
    }

    /** @return array<string, mixed> */
    private function payload(Photo $photo): array
    {
        $photo->load(['stories.author:id,name', 'comments.author:id,name', 'reactions.user:id,name']);

        return ['stories' => $photo->stories->map($this->textPayload(...)), 'comments' => $photo->comments->map($this->textPayload(...)), 'reactions' => $photo->reactions->map(fn (PhotoReaction $reaction) => ['user_id' => $reaction->user_id, 'name' => $reaction->user->name, 'reaction' => $reaction->reaction->value]), 'permissions' => ['can_interact' => Gate::allows('interact', $photo)]];
    }

    /** @return array<string, mixed> */
    private function textPayload(PhotoStory|PhotoComment $content): array
    {
        return ['id' => $content->id, 'body' => $content->body, 'author' => $content->author === null ? null : ['id' => $content->author->id, 'name' => $content->author->name], 'edited_at' => $content->edited_at?->toAtomString(), 'created_at' => $content->created_at?->toAtomString(), 'permissions' => ['can_edit' => Gate::allows('update', $content), 'can_remove' => Gate::allows('delete', $content)]];
    }
}
