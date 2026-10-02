<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\StorePhotoCommentRequest;
use App\Http\Requests\StorePhotoReactionRequest;
use App\Http\Requests\StorePhotoTextRequest;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\Story;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\PhotoQuery;
use App\Services\ActorPresentationService;
use App\Services\PhotoConversationManager;
use App\Stories\RichTextDocument;
use App\Stories\RichTextPresenter;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class PhotoConversationController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly PhotoConversationManager $manager,
        private readonly TenantContext $tenantContext,
        private readonly RichTextPresenter $presenter,
        private readonly ActorPresentationService $actors,
    ) {}

    public function index(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photo($request, $photo);

        $albumId = $request->string('album_id')->trim()->toString();
        $album = $albumId === '' ? null : $this->albumForPhoto($request, $target, $albumId);

        return response()->json(['data' => $this->payload($target, $album)]);
    }

    public function storeComment(FamilySpace $familySpace, string $photo, StorePhotoCommentRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $album = $this->albumForPhoto($request, $target, (string) $request->validated('album_id'));
        $this->authorizeInteraction($target, $album);
        $parentId = $request->validated('parent_comment_id');
        $created = $this->manager->createComment(
            $target,
            $album,
            $request->user(),
            $request->validated('body'),
            $request,
            is_string($parentId) ? $parentId : null,
        );

        return response()->json(['data' => $this->textPayload(
            $created,
            $target,
            false,
            $this->presentationsFor(collect([$created->author])->filter()),
        )], 201);
    }

    public function updateComment(FamilySpace $familySpace, string $photo, string $comment, StorePhotoTextRequest $request): JsonResponse
    {
        $target = $this->photo($request, $photo);
        $model = PhotoComment::query()->where('photo_id', $target->id)->findOrFail($comment);
        $album = $this->albumForPhoto($request, $target, $model->album_id ?? '');
        $this->authorizeInteraction($target, $album);
        Gate::authorize('update', $model);

        $updated = $this->manager->updateComment($model, $request->user(), $request->validated('body'), $request);

        return response()->json(['data' => $this->textPayload(
            $updated,
            $target,
            false,
            $this->presentationsFor(collect([$updated->author])->filter()),
        )]);
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
        $photo->load(['stories.author']);
        $comments = $photo->comments()
            ->withTrashed()
            ->where('album_id', $album?->id)
            ->whereNull('parent_comment_id')
            ->where(fn ($query) => $query
                ->whereNull('deleted_at')
                ->orWhereHas('replies'))
            ->with(['author', 'replies.author'])
            ->oldest()
            ->get();
        $reactions = $photo->reactions()->where('album_id', $album?->id)->with('user:id,name')->get();
        /** @var Collection<int, User> $authors */
        $authors = $photo->stories->pluck('author')
            ->merge($comments->pluck('author'))
            ->merge($comments->flatMap(fn (PhotoComment $comment) => $comment->replies->pluck('author')))
            ->filter(fn ($author): bool => $author instanceof User);
        $presentations = $this->presentationsFor($authors);

        return [
            'stories' => $photo->stories->map(
                fn (Story $story): array => $this->textPayload($story, $photo, false, $presentations)
            ),
            'comments' => $comments->map(fn (PhotoComment $comment): array => [
                ...$this->textPayload($comment, $photo, $album === null, $presentations),
                'replies' => $comment->replies->map(
                    fn (PhotoComment $reply): array => $this->textPayload(
                        $reply,
                        $photo,
                        $album === null,
                        $presentations,
                    )
                )->values(),
            ])->values(),
            'reactions' => $reactions->map(fn (PhotoReaction $reaction) => [
                'user_id' => $reaction->user_id,
                'name' => $reaction->user->name,
                'reaction' => $reaction->reaction->value,
            ]),
            'permissions' => [
                'can_interact' => $album !== null && $this->canInteract($photo, $album),
                'can_author_story' => Gate::allows('authorStory', $photo),
            ],
            'conversation_scope' => $album === null ? 'legacy' : 'album',
            'album_id' => $album?->id,
        ];
    }

    /**
     * @param  array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>  $presentations
     * @return array<string, mixed>
     */
    private function textPayload(
        Story|PhotoComment $content,
        Photo $photo,
        bool $readOnly,
        array $presentations,
    ): array {
        $deleted = $content instanceof PhotoComment && $content->trashed();
        $presentation = $content->author === null
            ? null
            : ($presentations[$content->author->id] ?? null);
        $author = $deleted || $content->author === null ? null : [
            'id' => $content->author->id,
            'name' => $presentation['display_name'] ?? $content->author->name,
            'person_id' => $presentation['person_id'] ?? null,
            'initials' => $presentation['initials'] ?? '',
            'portrait_thumbnail_url' => $presentation['portrait_thumbnail_url'] ?? null,
        ];

        return [
            'id' => $content->id,
            'parent_comment_id' => $content instanceof PhotoComment ? $content->parent_comment_id : null,
            'is_deleted' => $deleted,
            'body' => $deleted ? 'Comment deleted' : $content->body_plain_text,
            'body_document' => $deleted ? null : $content->body,
            'body_html' => $deleted ? null : ($content instanceof Story
                ? $this->presenter->html($content->body, $content, 'story_person_mentions', 'story_id',
                    $this->familySlug(), $this->actor(), $photo)
                : $this->presenter->html($content->body, $content, 'photo_comment_person_mentions', 'photo_comment_id',
                    $this->familySlug(), $this->actor(), $photo, RichTextDocument::COMMENT)),
            'author' => $author,
            'edited_at' => $deleted ? null : $content->edited_at?->toAtomString(),
            'created_at' => $content->created_at?->toAtomString(),
            'permissions' => [
                'can_edit' => ! $deleted && ! $readOnly && Gate::allows('update', $content),
                'can_remove' => ! $deleted && ! $readOnly && Gate::allows('delete', $content),
            ],
        ];
    }

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>
     */
    private function presentationsFor(Collection $users): array
    {
        return $this->actors->forUsers($users->values(), $this->actor());
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

    private function familySlug(): string
    {
        $familySpace = request()->route('familySpace');

        return $familySpace instanceof FamilySpace ? $familySpace->slug : (string) $familySpace;
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
