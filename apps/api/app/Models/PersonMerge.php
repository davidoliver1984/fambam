<?php

namespace App\Models;

use App\Enums\PersonMergeStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property PersonMergeStatus $status
 * @property array<string, mixed> $provenance
 * @property CarbonImmutable $merged_at
 * @property CarbonImmutable|null $reversed_at
 */
#[Fillable([
    'family_space_id',
    'survivor_person_id',
    'absorbed_person_id',
    'status',
    'provenance',
    'merged_by',
    'merged_at',
    'reversed_by',
    'reversed_at',
    'manual_correction_required_at',
])]
class PersonMerge extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => PersonMergeStatus::class,
            'provenance' => 'array',
            'merged_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
            'manual_correction_required_at' => 'immutable_datetime',
        ];
    }
}
