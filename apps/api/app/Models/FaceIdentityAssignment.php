<?php

namespace App\Models;

use App\Enums\FaceIdentityAssignmentStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FaceIdentityAssignmentStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id', 'face_observation_id', 'person_id', 'proposal_source',
    'status', 'proposed_by', 'resolved_by', 'resolved_at',
])]
class FaceIdentityAssignment extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(FaceObservation::class, 'face_observation_id');
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
            'status' => FaceIdentityAssignmentStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
