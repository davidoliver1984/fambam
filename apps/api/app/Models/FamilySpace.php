<?php

namespace App\Models;

use App\Enums\FamilySpaceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\FamilySpaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property FamilySpaceStatus $status
 * @property CarbonImmutable|null $deletion_requested_at
 * @property int|null $deletion_requested_by
 * @property CarbonImmutable|null $scheduled_deletion_at
 */
#[Fillable([
    'id',
    'slug',
    'name',
    'status',
    'deletion_requested_at',
    'deletion_requested_by',
    'scheduled_deletion_at',
])]
class FamilySpace extends Model
{
    /** @use HasFactory<FamilySpaceFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return HasMany<FamilySpaceMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(FamilySpaceMembership::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return HasMany<Person, $this> */
    public function persons(): HasMany
    {
        return $this->hasMany(Person::class);
    }

    /** @return HasMany<FamilyEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(FamilyEvent::class);
    }

    /** @return HasMany<FaceAnalysisRun, $this> */
    public function faceAnalysisRuns(): HasMany
    {
        return $this->hasMany(FaceAnalysisRun::class);
    }

    /** @return HasMany<PersonAccountLink, $this> */
    public function personAccountLinks(): HasMany
    {
        return $this->hasMany(PersonAccountLink::class);
    }

    /** @return HasMany<PersonRelationship, $this> */
    public function personRelationships(): HasMany
    {
        return $this->hasMany(PersonRelationship::class);
    }

    /** @return HasMany<FamilyCircle, $this> */
    public function familyCircles(): HasMany
    {
        return $this->hasMany(FamilyCircle::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FamilySpaceStatus::class,
            'deletion_requested_at' => 'immutable_datetime',
            'scheduled_deletion_at' => 'immutable_datetime',
        ];
    }
}
