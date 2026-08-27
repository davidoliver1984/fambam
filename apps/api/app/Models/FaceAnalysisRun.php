<?php

namespace App\Models;

use App\Enums\FaceAnalysisRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'family_space_id',
    'media_upload_id',
    'canonical_sha256',
    'contract_version',
    'provider',
    'model_identifier',
    'model_weight_checksum',
    'config_hash',
    'status',
    'attempt_count',
    'succeeded_at',
    'failed_at',
])]
class FaceAnalysisRun extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<MediaUpload, $this> */
    public function mediaUpload(): BelongsTo
    {
        return $this->belongsTo(MediaUpload::class);
    }

    /** @return HasMany<FaceAnalysisAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(FaceAnalysisAttempt::class);
    }

    /** @return HasMany<FaceObservation, $this> */
    public function observations(): HasMany
    {
        return $this->hasMany(FaceObservation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FaceAnalysisRunStatus::class,
            'attempt_count' => 'integer',
            'succeeded_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
