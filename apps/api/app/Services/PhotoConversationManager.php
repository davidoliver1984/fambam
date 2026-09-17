<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\PhotoReactionType;
use App\Jobs\ProcessNotificationCandidate;
use App\Models\Album;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoCommentRevision;
use App\Models\PhotoReaction;
use App\Models\User;
use App\Stories\MentionAuthorizer;
use App\Stories\RichTextDocument;
use App\Stories\RichTextFieldWriter;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhotoConversationManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly RichTextFieldWriter $richText,
        private readonly MentionAuthorizer $mentionAuthorizer,
        private readonly LoveNotificationManager $loveNotifications,
    ) {}

    public function createComment(Photo $photo, Album $album, User $actor, mixed $body, Request $request): PhotoComment
    {
        return DB::transaction(function () use ($photo, $album, $actor, $body, $request): PhotoComment {
            $comment = PhotoComment::query()->create([
                'family_space_id' => $photo->family_space_id,
                'photo_id' => $photo->id,
                'album_id' => $album->id,
                'author_id' => $actor->id,
                'body' => ['schema_version' => 1, 'blocks' => []],
            ]);
            $body = $this->richText->synchronize($comment, 'photo_comment_person_mentions', 'photo_comment_id', $body,
                $this->mentionAuthorizer->for($actor, $photo), RichTextDocument::COMMENT);
            $comment->update(['body' => $body]);
            $this->audit->record('photo_comment.created', $comment, $actor, $request, ['album_id' => $album->id]);
            $context = TenantOperationContext::fromRequest($photo->familySpace, $actor, $request);
            DB::afterCommit(fn () => ProcessNotificationCandidate::dispatch($context->toArray(), NotificationCategory::Comment, $comment->id, ['photo_id' => $photo->id, 'album_id' => $album->id, 'comment_id' => $comment->id]));

            return $comment->load('author:id,name');
        });
    }

    public function updateComment(PhotoComment $comment, User $actor, mixed $body, Request $request): PhotoComment
    {
        return DB::transaction(function () use ($comment, $actor, $body, $request): PhotoComment {
            $locked = PhotoComment::query()->lockForUpdate()->findOrFail($comment->id);
            $revision = ((int) PhotoCommentRevision::query()->where('photo_comment_id', $locked->id)->max('revision')) + 1;
            PhotoCommentRevision::query()->create(['family_space_id' => $locked->family_space_id,
                'photo_comment_id' => $locked->id, 'editor_id' => $actor->id, 'revision' => $revision, 'body' => $locked->body]);
            $body = $this->richText->synchronize($locked, 'photo_comment_person_mentions', 'photo_comment_id', $body,
                $this->mentionAuthorizer->for($actor, $locked->photo()->firstOrFail()), RichTextDocument::COMMENT);
            $locked->update(['body' => $body, 'edited_at' => now()]);
            $this->audit->record('photo_comment.updated', $locked, $actor, $request, ['revision' => $revision]);

            return $locked->load('author:id,name');
        });
    }

    public function remove(PhotoComment $content, User $actor, Request $request): void
    {
        DB::transaction(function () use ($content, $actor, $request): void {
            if ($content->author_id !== $actor->id) {
                $this->audit->record('photo_comment.removed', $content, $actor, $request);
            }
            $content->delete();
        });
    }

    public function react(Photo $photo, Album $album, User $actor, string $reaction, Request $request): PhotoReaction
    {
        return DB::transaction(function () use ($photo, $album, $actor, $reaction, $request): PhotoReaction {
            $previous = PhotoReaction::query()->where('photo_id', $photo->id)
                ->where('album_id', $album->id)->where('user_id', $actor->id)->first();
            $wasLove = $previous?->reaction === PhotoReactionType::Love;
            $model = PhotoReaction::query()->updateOrCreate(
                ['photo_id' => $photo->id, 'album_id' => $album->id, 'user_id' => $actor->id],
                ['family_space_id' => $photo->family_space_id, 'reaction' => $reaction],
            );
            $this->audit->record('photo.reaction_saved', $model, $actor, $request, ['album_id' => $album->id]);
            if ($reaction === PhotoReactionType::Love->value && ! $wasLove) {
                $this->loveNotifications->added($photo, $actor, $request);
            } elseif ($wasLove && $reaction !== PhotoReactionType::Love->value) {
                $this->loveNotifications->removed($photo, $actor);
            }

            return $model;
        });
    }

    public function removeReaction(Photo $photo, Album $album, User $actor, Request $request): void
    {
        DB::transaction(function () use ($photo, $album, $actor, $request): void {
            $reaction = PhotoReaction::query()->where('photo_id', $photo->id)
                ->where('album_id', $album->id)->where('user_id', $actor->id)->firstOrFail();
            $this->audit->record('photo.reaction_removed', $reaction, $actor, $request, ['album_id' => $album->id]);
            $reaction->delete();
            if ($reaction->reaction === PhotoReactionType::Love) {
                $this->loveNotifications->removed($photo, $actor);
            }
        });
    }
}
