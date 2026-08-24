<?php

namespace App\Models;

use App\Enums\PersonProposalStatus;
use App\Enums\PhotoProvenanceRole;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PersonProposalStatus $status
 * @property PhotoProvenanceRole $role
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id',
    'photo_id',
    'role',
    'person_id',
    'description',
    'clears_claim',
    'status',
    'proposed_by',
    'resolved_by',
    'resolved_at',
])]
class PhotoProvenanceProposal extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => PhotoProvenanceRole::class,
            'status' => PersonProposalStatus::class,
            'clears_claim' => 'boolean',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
