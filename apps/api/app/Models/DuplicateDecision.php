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
#[Fillable(['family_space_id', 'photo_low_id', 'photo_high_id', 'source', 'decided_by', 'decided_at', 'reopened_by', 'reopened_at'])]
class DuplicateDecision extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function lowPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_low_id')->withTrashed();
    }

    /** @return BelongsTo<Photo, $this> */
    public function highPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'photo_high_id')->withTrashed();
    }

    protected function casts(): array
    {
        return ['decided_at' => 'immutable_datetime', 'reopened_at' => 'immutable_datetime'];
    }
}
