<?php

namespace App\Models;

use App\Enums\PhotoReactionType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $family_space_id
 * @property int $user_id
 * @property PhotoReactionType $reaction
 * @property User $user
 */
#[Fillable(['family_space_id', 'photo_id', 'album_id', 'user_id', 'reaction'])]
class PhotoReaction extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    protected function casts(): array
    {
        return ['reaction' => PhotoReactionType::class];
    }
}
