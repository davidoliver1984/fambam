<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'face_cluster_id', 'face_observation_id', 'is_active'])]
class FaceClusterMember extends Model
{
    use HasUlids;

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceCluster, $this> */
    public function cluster(): BelongsTo
    {
        return $this->belongsTo(FaceCluster::class, 'face_cluster_id');
    }

    /** @return BelongsTo<FaceObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(FaceObservation::class, 'face_observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
