<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_space_id', 'recipient_user_id', 'category', 'source_action_id', 'notification_id', 'photo_id', 'album_id', 'story_id', 'person_id', 'comment_id', 'channel', 'status', 'attempted_at', 'sent_at', 'failure_reason'])]
class NotificationDelivery extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'attempted_at' => 'immutable_datetime', 'sent_at' => 'immutable_datetime'];
    }
}
