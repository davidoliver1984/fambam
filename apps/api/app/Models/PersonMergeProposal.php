<?php

namespace App\Models;

use App\Enums\PersonMergeProposalStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property PersonMergeProposalStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id',
    'survivor_person_id',
    'absorbed_person_id',
    'context',
    'status',
    'person_merge_id',
    'proposed_by',
    'resolved_by',
    'resolved_at',
])]
class PersonMergeProposal extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PersonMergeProposalStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
