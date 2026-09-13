<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePhotoTextRequest;
use App\Http\Requests\StoreStoryCommentRequest;
use App\Http\Requests\StoreStoryRequest;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Services\StoryManager;
use App\Stories\StoryHeading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class StoryController extends Controller
{
    public function __construct(private readonly StoryManager $manager, private readonly StoryHeading $headings) {}

    public function store(FamilySpace $familySpace, StoreStoryRequest $request): JsonResponse
    {
        $type = (string) $request->validated('subject_type');
        $subject = $this->subject($type, (string) $request->validated('subject_id'));
        Gate::authorize('createForSubject', [Story::class, $subject]);
        $story = $this->manager->create($familySpace->id, $request->user(), ["{$type}_id" => $subject->getKey()],
            $request->validated('body'), $this->mayMention($request->user(), $subject), $request);

        return response()->json(['data' => $this->payload($story)], 201);
    }

    public function storeForPhoto(FamilySpace $familySpace, string $photo, StorePhotoTextRequest $request): JsonResponse
    {
        $subject = Photo::query()->findOrFail($photo);
        Gate::authorize('createForSubject', [Story::class, $subject]);
        $body = $this->plainDocument((string) $request->validated('body'));
        $story = $this->manager->create($familySpace->id, $request->user(), ['photo_id' => $subject->id],
            $body, $this->mayMention($request->user(), $subject), $request);

        return response()->json(['data' => $this->legacyPayload($story)], 201);
    }

    public function updateForPhoto(FamilySpace $familySpace, string $photo, string $story, StorePhotoTextRequest $request): JsonResponse
    {
        $model = Story::query()->where('photo_id', $photo)->findOrFail($story);
        Gate::authorize('update', $model);
        $updated = $this->manager->update($model, $request->user(),
            $this->plainDocument((string) $request->validated('body')),
            $this->mayMention($request->user(), $model->photo()->firstOrFail()), $request);

        return response()->json(['data' => $this->legacyPayload($updated)]);
    }

    public function removeForPhoto(FamilySpace $familySpace, string $photo, string $story, Request $request): JsonResponse
    {
        $model = Story::query()->where('photo_id', $photo)->findOrFail($story);
        Gate::authorize('delete', $model);
        $this->manager->delete($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function show(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        $model = Story::query()->with(['author:id,name', 'comments.author:id,name'])->findOrFail($story);
        Gate::authorize('view', $model);

        return response()->json(['data' => $this->payload($model)]);
    }

    public function update(FamilySpace $familySpace, string $story, StoreStoryRequest $request): JsonResponse
    {
        $model = Story::query()->findOrFail($story);
        Gate::authorize('update', $model);

        return response()->json(['data' => $this->payload($this->manager->update($model, $request->user(),
            $request->validated('body'), $this->mayMention($request->user(), $this->storySubject($model)), $request))]);
    }

    public function destroy(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        $model = Story::query()->findOrFail($story);
        Gate::authorize('delete', $model);
        $this->manager->delete($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function restore(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        $model = Story::query()->withTrashed()->findOrFail($story);
        Gate::authorize('restore', $model);
        $this->manager->restore($model, $request->user(), $request);

        return response()->json(['data' => $this->payload($model->refresh())]);
    }

    public function comment(FamilySpace $familySpace, string $story, StoreStoryCommentRequest $request): JsonResponse
    {
        $model = Story::query()->findOrFail($story);
        Gate::authorize('create', [StoryComment::class, $model]);
        $comment = $this->manager->comment($model, $request->user(), $request->validated('body'),
            $this->mayMention($request->user(), $this->storySubject($model)), $request);

        return response()->json(['data' => $this->commentPayload($comment)], 201);
    }

    public function removeComment(FamilySpace $familySpace, string $story, string $comment, Request $request): JsonResponse
    {
        $model = StoryComment::query()->where('story_id', $story)->findOrFail($comment);
        Gate::authorize('delete', $model);
        $this->manager->deleteComment($model, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function updateComment(
        FamilySpace $familySpace,
        string $story,
        string $comment,
        StoreStoryCommentRequest $request,
    ): JsonResponse {
        $model = StoryComment::query()->where('story_id', $story)->findOrFail($comment);
        Gate::authorize('update', $model);
        $updated = $this->manager->updateComment(
            $model,
            $request->validated('body'),
            $this->mayMention($request->user(), $this->storySubject($model->story()->firstOrFail())),
        );

        return response()->json(['data' => $this->commentPayload($updated)]);
    }

    /** @return array<string, mixed> */
    private function payload(Story $story): array
    {
        $story->loadMissing(['author:id,name', 'comments.author:id,name']);
        $mentions = $story->mentions()->pluck('person_id', 'mention_id');
        $subjectType = collect(['person', 'album', 'event', 'photo'])->first(fn (string $type): bool => $story->{"{$type}_id"} !== null);

        return [
            'id' => $story->id,
            'heading' => $this->headings->derive($story->body, fn (string $id): ?string => Person::query()->whereKey($mentions[$id] ?? null)->value('preferred_name')),
            'body' => $story->body,
            'body_plain_text' => $story->body_plain_text,
            'subject' => ['type' => $subjectType, 'id' => $story->{"{$subjectType}_id"}],
            'author' => $story->author === null ? null : ['id' => $story->author->id, 'name' => $story->author->name],
            'comments' => $story->comments->map($this->commentPayload(...)),
            'created_at' => $story->created_at?->toAtomString(),
            'edited_at' => $story->edited_at?->toAtomString(),
            'permissions' => ['can_edit' => Gate::allows('update', $story), 'can_remove' => Gate::allows('delete', $story)],
        ];
    }

    /** @return array<string, mixed> */
    private function commentPayload(StoryComment $comment): array
    {
        $comment->loadMissing('author:id,name');

        return ['id' => $comment->id, 'body' => $comment->body,
            'author' => $comment->author === null ? null : ['id' => $comment->author->id, 'name' => $comment->author->name],
            'created_at' => $comment->created_at?->toAtomString(),
            'permissions' => ['can_remove' => Gate::allows('delete', $comment)]];
    }

    /** @return array<string, mixed> */
    private function legacyPayload(Story $story): array
    {
        $story->loadMissing('author:id,name');

        return ['id' => $story->id, 'body' => $story->body_plain_text,
            'author' => $story->author === null ? null : ['id' => $story->author->id, 'name' => $story->author->name],
            'edited_at' => $story->edited_at?->toAtomString(), 'created_at' => $story->created_at?->toAtomString(),
            'permissions' => ['can_edit' => Gate::allows('update', $story), 'can_remove' => Gate::allows('delete', $story)]];
    }

    /** @return array{schema_version: int, blocks: list<array<string, mixed>>} */
    private function plainDocument(string $body): array
    {
        return ['schema_version' => 1, 'blocks' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => trim($body)]]]]];
    }

    private function subject(string $type, string $id): Model
    {
        $class = match ($type) {
            'person' => Person::class, 'album' => Album::class, 'event' => FamilyEvent::class, 'photo' => Photo::class,
            default => throw new \InvalidArgumentException('Unsupported Story subject type.'),
        };

        return $class::query()->findOrFail($id);
    }

    private function storySubject(Story $story): Model
    {
        return match (true) {
            $story->person_id !== null => $story->person()->firstOrFail(), $story->album_id !== null => $story->album()->firstOrFail(), $story->event_id !== null => $story->event()->firstOrFail(), default => $story->photo()->firstOrFail()
        };
    }

    private function mayMention(User $actor, Model $subject): callable
    {
        return function (string $personId) use ($actor, $subject): bool {
            $person = Person::query()->find($personId);
            if ($person === null) {
                return false;
            }
            if (Gate::forUser($actor)->allows('view', $person)) {
                return true;
            }
            $photoIds = match (true) {
                $subject instanceof Photo => [$subject->id],
                $subject instanceof Album => DB::table('album_photos')->where('album_id', $subject->id)->pluck('photo_id')->all(),
                $subject instanceof FamilyEvent => DB::table('photos')->where('primary_event_id', $subject->id)->pluck('id')->merge(DB::table('album_photos')->join('albums', 'albums.id', '=', 'album_photos.album_id')->where('albums.event_id', $subject->id)->pluck('album_photos.photo_id'))->unique()->all(),
                default => [],
            };

            return DB::table('photo_people')->where('person_id', $personId)->where('status', 'approved')->whereIn('photo_id', $photoIds)->exists();
        };
    }
}
