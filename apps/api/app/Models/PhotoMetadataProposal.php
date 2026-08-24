<?php

namespace App\Models;

use App\Enums\DatePrecision;
use App\Enums\PersonProposalStatus;
use App\Enums\PhotoMetadataField;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property PhotoMetadataField $field
 * @property DatePrecision|null $date_precision
 * @property CarbonImmutable|null $date_value
 * @property PersonProposalStatus $status
 * @property CarbonImmutable|null $resolved_at
 */
#[Fillable([
    'family_space_id', 'photo_id', 'field', 'date_precision', 'date_value',
    'location_description', 'clears_claim', 'status', 'proposed_by',
    'resolved_by', 'resolved_at',
])]
class PhotoMetadataProposal extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'field' => PhotoMetadataField::class,
            'date_precision' => DatePrecision::class,
            'date_value' => 'immutable_date',
            'clears_claim' => 'boolean',
            'status' => PersonProposalStatus::class,
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
