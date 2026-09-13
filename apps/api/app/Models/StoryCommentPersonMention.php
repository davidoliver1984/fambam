<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['family_space_id', 'story_comment_id', 'mention_id', 'person_id', 'historical_label_snapshot'])]
class StoryCommentPersonMention extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<StoryComment, $this> */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(StoryComment::class, 'story_comment_id');
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
