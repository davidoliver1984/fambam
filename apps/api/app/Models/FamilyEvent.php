<?php

namespace App\Models;

use App\Enums\EventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property EventStatus $status
 * @property CarbonImmutable|null $starts_on
 * @property CarbonImmutable|null $ends_on
 */
#[Fillable(['family_space_id', 'created_by', 'name', 'description', 'starts_on', 'ends_on', 'location', 'status'])]
class FamilyEvent extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'events';

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

    /** @return HasMany<Album, $this> */
    public function albums(): HasMany
    {
        return $this->hasMany(Album::class, 'event_id');
    }

    /** @return HasMany<Photo, $this> */
    public function primaryPhotos(): HasMany
    {
        return $this->hasMany(Photo::class, 'primary_event_id');
    }

    /** @return HasMany<EventAdmission, $this> */
    public function admissions(): HasMany
    {
        return $this->hasMany(EventAdmission::class, 'event_id');
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class, 'event_id');
    }

    /** @return HasMany<EventExport, $this> */
    public function exports(): HasMany
    {
        return $this->hasMany(EventExport::class, 'event_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => EventStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }
}
