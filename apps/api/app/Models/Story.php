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
 * @property string|null $person_id
 * @property string|null $album_id
 * @property string|null $event_id
 * @property string|null $photo_id
 * @property array<string, mixed> $body
 * @property string $body_plain_text
 * @property string|null $deletion_operation_id
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable(['family_space_id', 'author_id', 'person_id', 'album_id', 'event_id', 'photo_id', 'body', 'body_plain_text', 'edited_at', 'deletion_operation_id'])]
class Story extends Model
{
    use HasUlids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<FamilyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class, 'event_id');
    }

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return HasMany<StoryRevision, $this> */
    public function revisions(): HasMany
    {
        return $this->hasMany(StoryRevision::class);
    }

    /** @return HasMany<StoryComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(StoryComment::class);
    }

    /** @return HasMany<StoryPersonMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(StoryPersonMention::class);
    }

    protected function casts(): array
    {
        return ['body' => 'array', 'edited_at' => 'immutable_datetime'];
    }
}
