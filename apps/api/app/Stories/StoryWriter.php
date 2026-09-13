<?php

namespace App\Stories;

use App\Models\Story;
use App\Models\StoryComment;
use App\Models\StoryRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StoryWriter
{
    public function __construct(
        private readonly RichTextDocument $documents,
        private readonly MentionSynchronizer $mentions,
    ) {}

    /**
     * @param  array{person_id?: string|null, album_id?: string|null, event_id?: string|null, photo_id?: string|null}  $subject
     * @param  array<string, mixed>  $body
     * @param  callable(string): bool  $mayMention
     */
    public function create(string $familySpaceId, User $author, array $subject, array $body, callable $mayMention): Story
    {
        return DB::transaction(function () use ($familySpaceId, $author, $subject, $body, $mayMention): Story {
            if (array_diff(array_keys($subject), ['person_id', 'album_id', 'event_id', 'photo_id']) !== []
                || count(array_filter($subject, fn ($value): bool => $value !== null)) !== 1) {
                throw ValidationException::withMessages(['subject' => 'A Story must have exactly one typed subject.']);
            }
            $this->documents->validate($body);
            $story = Story::query()->create([
                'family_space_id' => $familySpaceId,
                'author_id' => $author->id,
                ...$subject,
                'body' => ['schema_version' => 1, 'blocks' => []],
                'body_plain_text' => '',
            ]);
            $body = $this->mentions->synchronize($story, 'story_person_mentions', 'story_id', $body, $mayMention);
            $story->update(['body' => $body, 'body_plain_text' => $this->documents->plainText($body)]);

            return $story->refresh();
        });
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function update(Story $story, User $editor, array $body, callable $mayMention): Story
    {
        return DB::transaction(function () use ($story, $editor, $body, $mayMention): Story {
            $story = Story::query()->lockForUpdate()->findOrFail($story->id);
            $this->documents->validate($body);
            StoryRevision::query()->create([
                'family_space_id' => $story->family_space_id,
                'story_id' => $story->id,
                'editor_id' => $editor->id,
                'revision' => ((int) $story->revisions()->max('revision')) + 1,
                'body' => $story->body,
            ]);
            $body = $this->mentions->synchronize($story, 'story_person_mentions', 'story_id', $body, $mayMention);
            $story->update([
                'body' => $body,
                'body_plain_text' => $this->documents->plainText($body),
                'edited_at' => CarbonImmutable::now(),
            ]);

            return $story->refresh();
        });
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function comment(Story $story, User $author, array $body, callable $mayMention): StoryComment
    {
        return DB::transaction(function () use ($story, $author, $body, $mayMention): StoryComment {
            $this->documents->validate($body, RichTextDocument::COMMENT);
            $comment = StoryComment::query()->create([
                'family_space_id' => $story->family_space_id,
                'story_id' => $story->id,
                'author_id' => $author->id,
                'body' => ['schema_version' => 1, 'blocks' => []],
            ]);
            $body = $this->mentions->synchronize(
                $comment,
                'story_comment_person_mentions',
                'story_comment_id',
                $body,
                $mayMention,
            );
            $comment->update(['body' => $body]);

            return $comment->refresh();
        });
    }

    /** @param array<string, mixed> $body @param callable(string): bool $mayMention */
    public function updateComment(StoryComment $comment, array $body, callable $mayMention): StoryComment
    {
        return DB::transaction(function () use ($comment, $body, $mayMention): StoryComment {
            $comment = StoryComment::query()->lockForUpdate()->findOrFail($comment->id);
            $this->documents->validate($body, RichTextDocument::COMMENT);
            $body = $this->mentions->synchronize(
                $comment,
                'story_comment_person_mentions',
                'story_comment_id',
                $body,
                $mayMention,
            );
            $comment->update(['body' => $body]);

            return $comment->refresh();
        });
    }
}
