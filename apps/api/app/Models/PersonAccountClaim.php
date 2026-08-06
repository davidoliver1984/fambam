<?php

namespace App\Models;

use App\Enums\PersonAccountClaimStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PersonAccountClaimStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id',
    'person_id',
    'user_id',
    'status',
    'proposed_by',
    'resolved_by',
    'resolved_at',
])]
class PersonAccountClaim extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PersonAccountClaimStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
