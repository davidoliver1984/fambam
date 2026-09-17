<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $family_space_id
 * @property int $user_id
 * @property string|null $album_id
 * @property string|null $event_id
 * @property string|null $story_id
 */
#[Fillable(['family_space_id', 'album_id', 'event_id', 'story_id', 'user_id', 'reaction'])]
class Reaction extends Model
{
    use HasUlids;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
