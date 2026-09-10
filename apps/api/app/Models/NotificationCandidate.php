<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use App\Enums\NotificationOutcome;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['family_space_id', 'recipient_user_id', 'category', 'source_action_id', 'evaluated_at', 'in_app_outcome', 'email_outcome'])]
class NotificationCandidate extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['category' => NotificationCategory::class, 'in_app_outcome' => NotificationOutcome::class, 'email_outcome' => NotificationOutcome::class, 'evaluated_at' => 'immutable_datetime'];
    }
}
