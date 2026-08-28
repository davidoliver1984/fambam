<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'family_space_id',
    'face_analysis_run_id',
    'face_index',
    'bounds_x',
    'bounds_y',
    'bounds_width',
    'bounds_height',
    'landmarks',
    'landmark_scheme',
    'detection_confidence',
    'embedding',
    'embedding_dimension',
    'embedding_dtype',
    'quality_signals',
    'provider_diagnostics',
])]
class FaceObservation extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceAnalysisRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(FaceAnalysisRun::class, 'face_analysis_run_id');
    }

    /** @return HasOne<FaceEmbeddingProjection, $this> */
    public function projection(): HasOne
    {
        return $this->hasOne(FaceEmbeddingProjection::class);
    }

    /** @return HasMany<FaceClusterMember, $this> */
    public function clusterMemberships(): HasMany
    {
        return $this->hasMany(FaceClusterMember::class);
    }

    /** @return HasMany<FaceIdentityAssignment, $this> */
    public function identityAssignments(): HasMany
    {
        return $this->hasMany(FaceIdentityAssignment::class);
    }

    /** @return HasMany<FaceIdentitySuppression, $this> */
    public function identitySuppressions(): HasMany
    {
        return $this->hasMany(FaceIdentitySuppression::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'face_index' => 'integer',
            'bounds_x' => 'float',
            'bounds_y' => 'float',
            'bounds_width' => 'float',
            'bounds_height' => 'float',
            'landmarks' => 'array',
            'detection_confidence' => 'float',
            'embedding_dimension' => 'integer',
            'quality_signals' => 'array',
            'provider_diagnostics' => 'array',
        ];
    }
}
