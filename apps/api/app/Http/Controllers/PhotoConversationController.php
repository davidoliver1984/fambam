<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\StorePhotoReactionRequest;
use App\Http\Requests\StorePhotoTextRequest;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Queries\AlbumQuery;
use App\Queries\PhotoQuery;
use App\Services\PhotoConversationManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PhotoConversationController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly PhotoConversationManager $manager,
        private readonly TenantContext $tenantContext,
    ) {}

    public function index(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);

        $albumId = $request->string('album_id')->trim()->toString();
        $album = $albumId === '' ? null : $this->albumForPhoto($request, $target, $albumId);

        return response()->json(['data' => $this->payload($target, $album)]);
    }

    public function storeStory(FamilySpace $familySpace, string $photo, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        Gate::authorize('authorStory', $target);

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
        $album = $this->albumForPhoto($request, $target, (string) $request->validated('album_id'));
        $this->authorizeInteraction($target, $album);

        return response()->json(['data' => $this->textPayload($this->manager->createComment($target, $album, $request->user(), $request->validated('body'), $request))], 201);
    }

    public function updateComment(FamilySpace $familySpace, string $photo, string $comment, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoComment::query()->where('photo_id', $target->id)->findOrFail($comment);
        $album = $this->albumForPhoto($request, $target, $model->album_id ?? '');
        $this->authorizeInteraction($target, $album);
        Gate::authorize('update', $model);

        return response()->json(['data' => $this->textPayload($this->manager->updateComment($model, $request->user(), $request->validated('body'), $request))]);
    }

    public function removeComment(FamilySpace $familySpace, string $photo, string $comment, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoComment::query()->where('photo_id', $target->id)->findOrFail($comment);
        $album = $this->albumForPhoto($request, $target, $model->album_id ?? '');
        $this->authorizeInteraction($target, $album);
        Gate::authorize('delete', $model);
        $this->manager->remove($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function react(FamilySpace $familySpace, string $photo, StorePhotoReactionRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $album = $this->albumForPhoto($request, $target, (string) $request->validated('album_id'));
        $this->authorizeInteraction($target, $album);

        return response()->json(['data' => $this->manager->react($target, $album, $request->user(), $request->validated('reaction'), $request)]);
    }

    public function removeReaction(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $album = $this->albumForPhoto($request, $target, $request->string('album_id')->trim()->toString());
        $this->authorizeInteraction($target, $album);
        $this->manager->removeReaction($target, $album, $request->user(), $request);

        return response()->json(null, 204);
    }

    private function photo(Request $request, string $id): Photo
    {
        $photo = $this->photos->findVisibleTo($request->user(), $id);
        Gate::authorize('view', $photo);

        return $photo;
    }

    /** @return array<string, mixed> */
    private function payload(Photo $photo, ?Album $album): array
    {
        $photo->load(['stories.author:id,name']);
        $comments = $photo->comments()->where('album_id', $album?->id)->with('author:id,name')->get();
        $reactions = $photo->reactions()->where('album_id', $album?->id)->with('user:id,name')->get();

        return ['stories' => $photo->stories->map(fn (PhotoStory $story): array => $this->textPayload($story)), 'comments' => $comments->map(fn (PhotoComment $comment): array => $this->textPayload($comment, $album === null)), 'reactions' => $reactions->map(fn (PhotoReaction $reaction) => ['user_id' => $reaction->user_id, 'name' => $reaction->user->name, 'reaction' => $reaction->reaction->value]), 'permissions' => ['can_interact' => $album !== null && $this->canInteract($photo, $album), 'can_author_story' => Gate::allows('authorStory', $photo)], 'conversation_scope' => $album === null ? 'legacy' : 'album', 'album_id' => $album?->id];
    }

    /** @return array<string, mixed> */
    private function textPayload(PhotoStory|PhotoComment $content, bool $readOnly = false): array
    {
        return ['id' => $content->id, 'body' => $content->body, 'author' => $content->author === null ? null : ['id' => $content->author->id, 'name' => $content->author->name], 'edited_at' => $content->edited_at?->toAtomString(), 'created_at' => $content->created_at?->toAtomString(), 'permissions' => ['can_edit' => ! $readOnly && Gate::allows('update', $content), 'can_remove' => ! $readOnly && Gate::allows('delete', $content)]];
    }

    private function albumForPhoto(Request $request, Photo $photo, string $albumId): Album
    {
        if ($albumId === '') {
            abort(404);
        }
        $album = $this->albums->findVisibleTo($request->user(), $albumId);
        abort_unless($album->photos()->whereKey($photo->id)->exists(), 404);

        return $album;
    }

    private function authorizeInteraction(Photo $photo, Album $album): void
    {
        abort_unless($this->canInteract($photo, $album), 403);
    }

    private function canInteract(Photo $photo, Album $album): bool
    {
        if (! Gate::allows('interact', $photo)) {
            return false;
        }

        return $this->tenantContext->membership()->role !== FamilySpaceRole::Contributor
            || Gate::allows('contribute', $album);
    }
}
