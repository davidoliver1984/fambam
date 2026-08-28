<?php

namespace App\Models;

use App\Enums\FaceClusterStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property FaceClusterStatus $status */
#[Fillable(['family_space_id', 'clustering_generation_id', 'status'])]
class FaceCluster extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceClusterGeneration, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(FaceClusterGeneration::class, 'clustering_generation_id');
    }

    /** @return HasMany<FaceClusterMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(FaceClusterMember::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => FaceClusterStatus::class];
    }
}
