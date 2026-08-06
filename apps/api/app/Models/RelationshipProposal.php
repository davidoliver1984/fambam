<?php

namespace App\Models;

use App\Enums\RelationshipProposalAction;
use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RelationshipProposalAction $action
 * @property RelationshipType|null $type
 * @property RelationshipProposalStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id',
    'action',
    'relationship_id',
    'subject_person_id',
    'related_person_id',
    'type',
    'context',
    'status',
    'proposed_by',
    'resolved_by',
    'resolved_at',
])]
class RelationshipProposal extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<PersonRelationship, $this> */
    public function relationship(): BelongsTo
    {
        return $this->belongsTo(PersonRelationship::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => RelationshipProposalAction::class,
            'type' => RelationshipType::class,
            'status' => RelationshipProposalStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
