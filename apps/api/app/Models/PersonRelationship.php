<?php

namespace App\Models;

use App\Enums\DatePrecision;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property RelationshipType $type
 * @property RelationshipStatus $status
 * @property DatePrecision $relationship_started_on_precision
 * @property CarbonImmutable|null $relationship_started_on
 */
#[Fillable([
    'family_space_id',
    'subject_person_id',
    'related_person_id',
    'type',
    'status',
    'context',
    'relationship_started_on',
    'relationship_started_on_precision',
    'created_by',
    'updated_by',
])]
class PersonRelationship extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Person, $this> */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'subject_person_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function related(): BelongsTo
    {
        return $this->belongsTo(Person::class, 'related_person_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => RelationshipType::class,
            'status' => RelationshipStatus::class,
            'relationship_started_on' => 'immutable_date',
            'relationship_started_on_precision' => DatePrecision::class,
        ];
    }
}
