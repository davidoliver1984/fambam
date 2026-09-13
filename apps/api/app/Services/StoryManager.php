<?php

namespace App\Services;

use App\Enums\FamilyActivityType;
use App\Enums\NotificationCategory;
use App\Jobs\ProcessNotificationCandidate;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Stories\StoryLifecycleManager;
use App\Stories\StoryWriter;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class StoryManager
{
    public function __construct(
        private readonly StoryWriter $writer,
        private readonly StoryLifecycleManager $lifecycle,
        private readonly AuditRecorder $audit,
        private readonly FamilyActivityRecorder $activities,
    ) {}

    /**
     * @param  array<string, string|null>  $subject
     * @param  array<string, mixed>  $body
     * @param  callable(string): bool  $mayMention
     */
    public function create(string $familySpaceId, User $actor, array $subject, array $body, callable $mayMention, Request $request): Story
    {
        return DB::transaction(function () use ($familySpaceId, $actor, $subject, $body, $mayMention, $request): Story {
            $story = $this->writer->create($familySpaceId, $actor, $subject, $body, $mayMention);
            $this->audit->record('story.created', $story, $actor, $request);
            $this->activities->record($familySpaceId, $actor->id, FamilyActivityType::StoryAdded,
                subjectStoryId: $story->id, photoIds: $story->photo_id === null ? [] : [$story->photo_id]);
            $context = TenantOperationContext::fromRequest($story->familySpace, $actor, $request);
            DB::afterCommit(fn () => ProcessNotificationCandidate::dispatch(
                $context->toArray(), NotificationCategory::Story, $story->id, ['story_id' => $story->id],
            ));

            return $story;
        });
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function update(Story $story, User $actor, array $body, callable $mayMention, Request $request): Story
    {
        $updated = $this->writer->update($story, $actor, $body, $mayMention);
        $this->audit->record('story.updated', $updated, $actor, $request);

        return $updated;
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function comment(Story $story, User $actor, array $body, callable $mayMention, Request $request): StoryComment
    {
        return DB::transaction(function () use ($story, $actor, $body, $mayMention, $request): StoryComment {
            $comment = $this->writer->comment($story, $actor, $body, $mayMention);
            $this->audit->record('story_comment.created', $comment, $actor, $request);
            $context = TenantOperationContext::fromRequest($story->familySpace, $actor, $request);
            DB::afterCommit(fn () => ProcessNotificationCandidate::dispatch($context->toArray(),
                NotificationCategory::Comment, $comment->id,
                ['story_id' => $story->id, 'story_comment_id' => $comment->id]));

            return $comment;
        });
    }

    public function delete(Story $story, User $actor, Request $request): void
    {
        if ($story->author_id !== $actor->id) {
            $this->audit->record('story.removed', $story, $actor, $request);
        }
        $this->lifecycle->delete($story);
    }

    public function restore(Story $story, User $actor, Request $request): void
    {
        $this->lifecycle->restore($story);
        $this->audit->record('story.restored', $story, $actor, $request);
    }

    public function deleteComment(StoryComment $comment, User $actor, Request $request): void
    {
        if ($comment->author_id !== $actor->id) {
            $this->audit->record('story_comment.removed', $comment, $actor, $request);
        }
        $comment->delete();
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function updateComment(StoryComment $comment, array $body, callable $mayMention): StoryComment
    {
        return $this->writer->updateComment($comment, $body, $mayMention);
    }
}
