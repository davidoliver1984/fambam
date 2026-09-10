<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property int $recipient_user_id
 * @property NotificationCategory $category
 * @property string $source_action_id
 * @property string|null $photo_id
 * @property string|null $album_id
 * @property string|null $story_id
 * @property string|null $person_id
 * @property string|null $comment_id
 * @property string|null $family_export_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['family_space_id', 'recipient_user_id', 'category', 'source_action_id', 'photo_id', 'album_id', 'story_id', 'person_id', 'comment_id', 'family_export_id', 'read_at'])]
class FamilyNotification extends Model
{
    use HasUlids;

    protected $table = 'notifications';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'read_at' => 'immutable_datetime'];
    }
}
