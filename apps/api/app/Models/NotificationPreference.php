<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property NotificationCategory $category
 * @property NotificationChannel $channel
 * @property bool $enabled
 */
#[Fillable(['family_space_id', 'user_id', 'category', 'channel', 'enabled'])]
class NotificationPreference extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'channel' => NotificationChannel::class, 'enabled' => 'boolean'];
    }
}
