<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'photo_story_id', 'editor_id', 'revision', 'body'])]
class PhotoStoryRevision extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @return BelongsTo<PhotoStory, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(PhotoStory::class, 'photo_story_id');
    }
}
