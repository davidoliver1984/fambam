<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'photo_id', 'candidate_photo_id', 'source', 'status', 'matched_sha256', 'algorithm', 'processing_version', 'score'])]
class DuplicateCandidate extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return BelongsTo<Photo, $this> */
    public function candidatePhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'candidate_photo_id');
    }
}
