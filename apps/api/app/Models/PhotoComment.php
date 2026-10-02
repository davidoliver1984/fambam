<?php

namespace App\Models;

use App\Casts\RichTextDocumentCast;
use App\Stories\RichTextDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property string $family_space_id
 * @property int|null $author_id
 * @property string|null $parent_comment_id
 * @property array<string, mixed> $body
 * @property string $body_plain_text
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $created_at
 * @property User|null $author
 */
#[Fillable(['family_space_id', 'photo_id', 'album_id', 'parent_comment_id', 'author_id', 'body', 'edited_at'])]
class PhotoComment extends Model
{
    use HasUlids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<PhotoComment, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_comment_id');
    }

    /** @return HasMany<PhotoComment, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_comment_id')->oldest();
    }

    /** @return HasMany<PhotoCommentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PhotoCommentRevision::class);
    }

    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime',
            'body' => RichTextDocumentCast::class.':'.RichTextDocument::COMMENT.',body_plain_text'];
    }
}
