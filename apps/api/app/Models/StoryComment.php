<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property array<string, mixed> $body
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['family_space_id', 'story_id', 'author_id', 'body', 'deleted_with_story_operation_id'])]
class StoryComment extends Model
{
    use HasUlids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<StoryCommentPersonMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(StoryCommentPersonMention::class);
    }

    protected function casts(): array
    {
        return ['body' => 'array'];
    }
}
