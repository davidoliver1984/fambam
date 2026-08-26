<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoCommentRevision;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\PhotoStoryRevision;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PhotoConversationManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function createStory(Photo $photo, User $actor, string $body, Request $request): PhotoStory
    {
        return $this->create(PhotoStory::class, 'photo_story.created', $photo, $actor, $body, $request);
    }

    public function createComment(Photo $photo, Album $album, User $actor, string $body, Request $request): PhotoComment
    {
        return DB::transaction(function () use ($photo, $album, $actor, $body, $request): PhotoComment {
            $comment = PhotoComment::query()->create([
                'family_space_id' => $photo->family_space_id,
                'photo_id' => $photo->id,
                'album_id' => $album->id,
                'author_id' => $actor->id,
                'body' => trim($body),
            ]);
            $this->audit->record('photo_comment.created', $comment, $actor, $request, ['album_id' => $album->id]);

            return $comment->load('author:id,name');
        });
    }

    public function updateStory(PhotoStory $story, User $actor, string $body, Request $request): PhotoStory
    {
        return DB::transaction(function () use ($story, $actor, $body, $request): PhotoStory {
            $locked = PhotoStory::query()->lockForUpdate()->findOrFail($story->id);
            $revision = ((int) PhotoStoryRevision::query()->where('photo_story_id', $locked->id)->max('revision')) + 1;
            PhotoStoryRevision::query()->create(['family_space_id' => $locked->family_space_id,
                'photo_story_id' => $locked->id, 'editor_id' => $actor->id, 'revision' => $revision, 'body' => $locked->body]);
            $locked->update(['body' => trim($body), 'edited_at' => now()]);
            $this->audit->record('photo_story.updated', $locked, $actor, $request, ['revision' => $revision]);

            return $locked->load('author:id,name');
        });
    }

    public function updateComment(PhotoComment $comment, User $actor, string $body, Request $request): PhotoComment
    {
        return DB::transaction(function () use ($comment, $actor, $body, $request): PhotoComment {
            $locked = PhotoComment::query()->lockForUpdate()->findOrFail($comment->id);
            $revision = ((int) PhotoCommentRevision::query()->where('photo_comment_id', $locked->id)->max('revision')) + 1;
            PhotoCommentRevision::query()->create(['family_space_id' => $locked->family_space_id,
                'photo_comment_id' => $locked->id, 'editor_id' => $actor->id, 'revision' => $revision, 'body' => $locked->body]);
            $locked->update(['body' => trim($body), 'edited_at' => now()]);
            $this->audit->record('photo_comment.updated', $locked, $actor, $request, ['revision' => $revision]);

            return $locked->load('author:id,name');
        });
    }

    public function remove(PhotoStory|PhotoComment $content, User $actor, Request $request): void
    {
        DB::transaction(function () use ($content, $actor, $request): void {
            if ($content->author_id !== $actor->id) {
                $this->audit->record($content instanceof PhotoStory ? 'photo_story.removed' : 'photo_comment.removed', $content, $actor, $request);
            }
            $content->delete();
        });
    }

    public function react(Photo $photo, Album $album, User $actor, string $reaction, Request $request): PhotoReaction
    {
        return DB::transaction(function () use ($photo, $album, $actor, $reaction, $request): PhotoReaction {
            $model = PhotoReaction::query()->updateOrCreate(
                ['photo_id' => $photo->id, 'album_id' => $album->id, 'user_id' => $actor->id],
                ['family_space_id' => $photo->family_space_id, 'reaction' => $reaction],
            );
            $this->audit->record('photo.reaction_saved', $model, $actor, $request, ['album_id' => $album->id]);

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
        });
    }

    /**
     * @template TModel of PhotoStory|PhotoComment
     *
     * @param  class-string<TModel>  $class
     * @return TModel
     */
    private function create(string $class, string $action, Photo $photo, User $actor, string $body, Request $request): PhotoStory|PhotoComment
    {
        return DB::transaction(function () use ($class, $action, $photo, $actor, $body, $request): PhotoStory|PhotoComment {
            $model = $class::query()->create(['family_space_id' => $photo->family_space_id, 'photo_id' => $photo->id, 'author_id' => $actor->id, 'body' => trim($body)]);
            $this->audit->record($action, $model, $actor, $request);

            return $model->load('author:id,name');
        });
    }
}
