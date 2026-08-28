<?php

namespace App\Models;

use App\Enums\FaceClusterGenerationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** @property FaceClusterGenerationStatus $status */
#[Fillable(['family_space_id', 'status', 'activated_at', 'superseded_at'])]
class FaceClusterGeneration extends Model
{
    use HasUlids;

    public $incrementing = false;

    public const UPDATED_AT = null;

    protected $keyType = 'string';

    /** @return HasMany<FaceCluster, $this> */
    public function clusters(): HasMany
    {
        return $this->hasMany(FaceCluster::class, 'clustering_generation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FaceClusterGenerationStatus::class,
            'activated_at' => 'immutable_datetime',
            'superseded_at' => 'immutable_datetime',
        ];
    }
}
