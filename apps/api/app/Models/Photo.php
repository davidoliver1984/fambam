<?php

namespace App\Models;

use App\Enums\DatePrecision;
use App\Enums\PhotoVisibility;
use Carbon\CarbonImmutable;
use Database\Factories\PhotoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property PhotoVisibility $visibility
 * @property DatePrecision|null $historical_date_precision
 * @property CarbonImmutable|null $historical_date
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'family_space_id',
    'media_upload_id',
    'created_by',
    'visibility',
    'caption',
    'description',
    'archive_source_description',
    'photographer_person_id',
    'photographer_description',
    'scanner_person_id',
    'scanner_description',
    'physical_owner_person_id',
    'physical_source_description',
    'historical_date_precision',
    'historical_date',
    'location_description',
    'deleted_by',
])]
class Photo extends Model
{
    /** @use HasFactory<PhotoFactory> */
    use HasFactory, HasUlids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<MediaUpload, $this> */
    public function mediaUpload(): BelongsTo
    {
        return $this->belongsTo(MediaUpload::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<Person, $this> */
    public function photographer(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'photographer_person_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function scanner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'scanner_person_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function physicalOwner(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'physical_owner_person_id');
    }

    /** @return HasMany<PhotoProvenanceProposal, $this> */
    public function provenanceProposals(): HasMany
    {
        return $this->hasMany(PhotoProvenanceProposal::class);
    }

    /** @return HasMany<PhotoMetadataProposal, $this> */
    public function metadataProposals(): HasMany
    {
        return $this->hasMany(PhotoMetadataProposal::class);
    }

    /** @return HasMany<PhotoPerson, $this> */
    public function photoPeople(): HasMany
    {
        return $this->hasMany(PhotoPerson::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot(['family_space_id', 'added_by', 'created_at']);
    }

    /** @return HasMany<AlbumPhoto, $this> */
    public function albumPhotos(): HasMany
    {
        return $this->hasMany(AlbumPhoto::class);
    }

    /** @return BelongsToMany<Album, $this> */
    public function albums(): BelongsToMany
    {
        return $this->belongsToMany(Album::class, 'album_photos')
            ->withPivot(['id', 'family_space_id', 'position', 'added_by'])
            ->withTimestamps();
    }

    /** @return HasMany<PhotoStory, $this> */
    public function stories(): HasMany
    {
        return $this->hasMany(PhotoStory::class);
    }

    /** @return HasMany<PhotoComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(PhotoComment::class);
    }

    /** @return HasMany<PhotoReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(PhotoReaction::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'visibility' => PhotoVisibility::class,
            'historical_date_precision' => DatePrecision::class,
            'historical_date' => 'immutable_date',
        ];
    }
}
