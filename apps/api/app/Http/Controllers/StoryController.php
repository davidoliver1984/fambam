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
use App\Services\ActorPresentationService;
use App\Services\StoryManager;
use App\Services\StoryPresentationResolver;
use App\Stories\MentionAuthorizer;
use App\Stories\RichTextDocument;
use App\Stories\RichTextPresenter;
use App\Stories\StoryHeading;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class StoryController extends Controller
{
    public function __construct(
        private readonly StoryManager $manager,
        private readonly StoryHeading $headings,
        private readonly MentionAuthorizer $mentionAuthorizer,
        private readonly RichTextPresenter $presenter,
        private readonly ActorPresentationService $actors,
        private readonly StoryPresentationResolver $presentation,
    ) {}

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
        $model = Story::query()->with([
            'author:id,name',
            'mentions.person:id,family_space_id,preferred_name',
            'comments.author:id,name',
            'comments.mentions.person:id,family_space_id,preferred_name',
        ])->findOrFail($story);
        Gate::authorize('view', $model);

        return response()->json(['data' => $this->payload($model)]);
    }

    public function mentionSuggestions(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        $values = $request->validate(['prefix' => ['required', 'string', 'min:1', 'max:80']]);
        $model = Story::query()->findOrFail($story);
        Gate::authorize('view', $model);
        $subject = $this->storySubject($model);

        return response()->json(['data' => $this->mentionAuthorizer->suggestions(
            $request->user(),
            $subject,
            (string) $values['prefix'],
        )]);
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
        $story->loadMissing([
            'author:id,name',
            'mentions.person:id,family_space_id,preferred_name',
            'comments.author:id,name',
            'comments.mentions.person:id,family_space_id,preferred_name',
        ]);
        $subject = $this->storySubject($story);
        $users = collect([$story->author])
            ->merge($story->comments->pluck('author'))
            ->filter(fn (mixed $user): bool => $user instanceof User);
        $actorPresentations = $this->actors->forUsers($users, $this->actor());
        $mentionPeople = $story->mentions->filter(fn ($mention): bool => $mention->person !== null)
            ->mapWithKeys(fn ($mention): array => [$mention->mention_id => $mention->person])->all();

        return [
            'id' => $story->id,
            'heading' => $this->headings->derive(
                $story->body,
                fn (string $id): ?string => isset($mentionPeople[$id])
                    ? $mentionPeople[$id]->preferred_name : null,
            ),
            'body' => $story->body,
            'body_html' => $this->presenter->htmlWithPeople(
                $story->body,
                $mentionPeople,
                $this->familySlug(),
                $this->actor(),
                $subject,
            ),
            'body_plain_text' => $story->body_plain_text,
            'subject' => $this->presentation->subject($subject),
            'hero' => $this->presentation->hero($subject, $this->actor()),
            'author' => $this->actorPayload($story->author, $actorPresentations),
            'comments' => $story->comments->map(function (StoryComment $comment) use ($story, $subject, $actorPresentations): array {
                $comment->setRelation('story', $story);

                return $this->commentPayload($comment, $subject, $actorPresentations);
            }),
            'created_at' => $story->created_at?->toAtomString(),
            'edited_at' => $story->edited_at?->toAtomString(),
            'permissions' => ['can_edit' => Gate::allows('update', $story), 'can_remove' => Gate::allows('delete', $story)],
        ];
    }

    /** @return array<string, mixed> */
    /**
     * @param  array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>|null  $actorPresentations
     * @return array<string, mixed>
     */
    private function commentPayload(StoryComment $comment, ?Model $subject = null, ?array $actorPresentations = null): array
    {
        $comment->loadMissing(['author:id,name', 'mentions.person:id,family_space_id,preferred_name']);
        $subject ??= $this->storySubject($comment->story()->firstOrFail());
        $actorPresentations ??= $this->actors->forUsers(
            collect([$comment->author])->filter(fn (mixed $user): bool => $user instanceof User),
            $this->actor(),
        );

        $mentionPeople = $comment->mentions->filter(fn ($mention): bool => $mention->person !== null)
            ->mapWithKeys(fn ($mention): array => [$mention->mention_id => $mention->person])->all();

        return ['id' => $comment->id, 'body' => $comment->body,
            'body_html' => $this->presenter->htmlWithPeople(
                $comment->body,
                $mentionPeople,
                $this->familySlug(),
                $this->actor(),
                $subject,
                RichTextDocument::COMMENT,
            ),
            'author' => $this->actorPayload($comment->author, $actorPresentations),
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
        [$relation, $subject] = match (true) {
            $story->person_id !== null => ['person', $story->relationLoaded('person') ? $story->person : $story->person()->firstOrFail()],
            $story->album_id !== null => ['album', $story->relationLoaded('album') ? $story->album : $story->album()->firstOrFail()],
            $story->event_id !== null => ['event', $story->relationLoaded('event') ? $story->event : $story->event()->firstOrFail()],
            default => ['photo', $story->relationLoaded('photo') ? $story->photo : $story->photo()->firstOrFail()],
        };
        $story->setRelation($relation, $subject);

        return $subject;
    }

    /**
     * @param  array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>  $presentations
     * @return array{id: int|null, display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}
     */
    private function actorPayload(?User $user, array $presentations): array
    {
        return ['id' => $user?->id, ...($user === null
            ? $this->actors->formerMember()
            : ($presentations[$user->id] ?? [
                'display_name' => $user->name,
                'person_id' => null,
                'initials' => collect(preg_split('/\s+/', trim($user->name)) ?: [])->filter()->take(2)
                    ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''),
                'portrait_thumbnail_url' => null,
            ]))];
    }

    private function mayMention(User $actor, Model $subject): callable
    {
        return $this->mentionAuthorizer->for($actor, $subject);
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
