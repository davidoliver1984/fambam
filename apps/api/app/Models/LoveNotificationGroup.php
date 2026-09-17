<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $family_space_id
 * @property string|null $photo_id
 * @property string|null $album_id
 * @property string|null $event_id
 * @property string|null $story_id
 */
#[Fillable(['family_space_id', 'photo_id', 'album_id', 'event_id', 'story_id'])]
class LoveNotificationGroup extends Model
{
    use HasUlids;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
