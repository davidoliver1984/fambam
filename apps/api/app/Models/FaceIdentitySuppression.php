<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $decided_at
 * @property CarbonImmutable|null $reopened_at
 */
#[Fillable([
    'family_space_id', 'face_observation_id', 'person_id', 'decided_by',
    'decided_at', 'reopened_by', 'reopened_at',
])]
class FaceIdentitySuppression extends Model
{
    use HasUlids;

    public $incrementing = false;

    public const UPDATED_AT = null;

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
            'decided_at' => 'immutable_datetime',
            'reopened_at' => 'immutable_datetime',
        ];
    }
}
