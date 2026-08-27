<?php

namespace App\Models;

use App\Enums\FaceAnalysisAttemptStatus;
use App\Enums\FaceAnalysisFailureCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_space_id',
    'face_analysis_run_id',
    'expected_result_object_key',
    'status',
    'failure_category',
    'failure_detail',
    'dispatched_at',
    'resolved_at',
])]
class FaceAnalysisAttempt extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceAnalysisRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(FaceAnalysisRun::class, 'face_analysis_run_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FaceAnalysisAttemptStatus::class,
            'failure_category' => FaceAnalysisFailureCategory::class,
            'dispatched_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
