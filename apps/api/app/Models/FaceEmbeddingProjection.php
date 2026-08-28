<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'family_space_id',
    'face_observation_id',
    'projection_version',
    'source_checksum',
    'embedding_dimension',
])]
class FaceEmbeddingProjection extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FaceObservation, $this> */
    public function observation(): BelongsTo
    {
        return $this->belongsTo(FaceObservation::class, 'face_observation_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['embedding_dimension' => 'integer'];
    }
}
