<?php

namespace App\Models;

use App\Enums\DatePrecision;
use App\Enums\PersonIdentityStatus;
use Carbon\CarbonImmutable;
use Database\Factories\PersonFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property list<string>|null $alternate_names
 * @property PersonIdentityStatus $identity_status
 * @property CarbonImmutable|null $birth_date
 * @property DatePrecision $birth_date_precision
 * @property bool $is_deceased
 * @property CarbonImmutable|null $death_date
 * @property DatePrecision $death_date_precision
 * @property CarbonImmutable|null $confirmed_at
 */
#[Fillable([
    'family_space_id',
    'preferred_name',
    'alternate_names',
    'identity_status',
    'birth_date',
    'birth_date_precision',
    'is_deceased',
    'death_date',
    'death_date_precision',
    'biography',
    'created_by',
    'confirmed_by',
    'confirmed_at',
])]
class Person extends Model
{
    /** @use HasFactory<PersonFactory> */
    use HasFactory, HasUlids, SoftDeletes;

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

    /** @return BelongsTo<User, $this> */
    public function confirmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    /** @return HasMany<PersonDetailProposal, $this> */
    public function detailProposals(): HasMany
    {
        return $this->hasMany(PersonDetailProposal::class);
    }

    /** @return HasMany<PersonAccountClaim, $this> */
    public function accountClaims(): HasMany
    {
        return $this->hasMany(PersonAccountClaim::class);
    }

    /** @return HasOne<PersonAccountLink, $this> */
    public function accountLink(): HasOne
    {
        return $this->hasOne(PersonAccountLink::class);
    }

    /** @return HasMany<PhotoPerson, $this> */
    public function photoPeople(): HasMany
    {
        return $this->hasMany(PhotoPerson::class);
    }

    /** @return HasMany<FaceIdentityAssignment, $this> */
    public function faceIdentityAssignments(): HasMany
    {
        return $this->hasMany(FaceIdentityAssignment::class);
    }

    /** @return HasMany<FaceIdentitySuppression, $this> */
    public function faceIdentitySuppressions(): HasMany
    {
        return $this->hasMany(FaceIdentitySuppression::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'alternate_names' => 'array',
            'identity_status' => PersonIdentityStatus::class,
            'birth_date' => 'immutable_date',
            'birth_date_precision' => DatePrecision::class,
            'is_deceased' => 'boolean',
            'death_date' => 'immutable_date',
            'death_date_precision' => DatePrecision::class,
            'confirmed_at' => 'immutable_datetime',
        ];
    }
}
