<?php

namespace App\Models;

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
 * @property string $body
 * @property CarbonImmutable|null $edited_at
 * @property CarbonImmutable|null $created_at
 * @property User|null $author
 */
#[Fillable(['family_space_id', 'photo_id', 'album_id', 'author_id', 'body', 'edited_at'])]
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

    /** @return HasMany<PhotoCommentRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(PhotoCommentRevision::class);
    }

    protected function casts(): array
    {
        return ['edited_at' => 'immutable_datetime'];
    }
}
