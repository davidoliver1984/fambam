<?php

namespace App\Models;

use App\Enums\FaceObservationReviewDisposition;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FaceObservationReviewDisposition $disposition
 * @property CarbonImmutable $reviewed_at
 */
#[Fillable(['family_space_id', 'face_observation_id', 'disposition', 'reviewed_by', 'reviewed_at'])]
class FaceObservationReview extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(FaceObservation::class, 'face_observation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'disposition' => FaceObservationReviewDisposition::class,
            'reviewed_at' => 'immutable_datetime',
        ];
    }
}
