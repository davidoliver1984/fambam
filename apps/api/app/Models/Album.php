<?php

namespace App\Models;

use App\Casts\RichTextDocumentCast;
use App\Enums\AlbumVisibility;
use App\Enums\GuestParticipation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property AlbumVisibility $visibility
 * @property GuestParticipation $guest_participation
 * @property array<string, mixed>|null $description
 * @property string|null $description_plain_text
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 * @property CarbonImmutable|null $deleting_at
 */
#[Fillable(['family_space_id', 'created_by', 'name', 'description', 'visibility', 'event_id', 'guest_participation',
    'starts_on', 'ends_on', 'location', 'cover_photo_id', 'cover_focal_x', 'cover_focal_y', 'current_cover_intent_id',
    'deleting_at'])]
class Album extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<FamilyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class, 'event_id');
    }

    /** @return HasMany<AlbumPhoto, $this> */
    public function albumPhotos(): HasMany
    {
        return $this->hasMany(AlbumPhoto::class);
    }

    /** @return HasMany<AlbumGrant, $this> */
    public function grants(): HasMany
    {
        return $this->hasMany(AlbumGrant::class);
    }

    /** @return BelongsToMany<Photo, $this> */
    public function photos(): BelongsToMany
    {
        return $this->belongsToMany(Photo::class, 'album_photos')
            ->withPivot(['id', 'family_space_id', 'position', 'added_by'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    /** @return BelongsTo<Photo, $this> */
    public function coverPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'cover_photo_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'album_tag')->withPivot(['family_space_id', 'added_by', 'created_at']);
    }

    /** @return BelongsToMany<Person, $this> */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(Person::class, 'album_people')->withPivot(['id', 'family_space_id', 'added_by', 'created_at']);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visibility' => AlbumVisibility::class, 'guest_participation' => GuestParticipation::class,
            'description' => RichTextDocumentCast::class.':full,description_plain_text',
            'starts_on' => 'immutable_date', 'ends_on' => 'immutable_date', 'deleting_at' => 'immutable_datetime'];
    }
}
