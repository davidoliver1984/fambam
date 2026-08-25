<?php

namespace App\Models;

use App\Enums\AlbumVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property AlbumVisibility $visibility */
#[Fillable(['family_space_id', 'created_by', 'name', 'description', 'visibility', 'event_id'])]
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['visibility' => AlbumVisibility::class];
    }
}
