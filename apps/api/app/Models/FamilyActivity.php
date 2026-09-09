<?php

namespace App\Models;

use App\Enums\FamilyActivityType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $family_space_id
 * @property int $actor_user_id
 * @property string|null $actor_person_id
 * @property FamilyActivityType $action_type
 * @property string|null $subject_album_id
 * @property string|null $subject_event_id
 * @property string|null $subject_story_id
 * @property string|null $subject_person_id
 * @property string|null $contribution_batch_id
 * @property list<string>|null $photo_ids
 * @property CarbonImmutable $created_at
 */
#[Fillable([
    'family_space_id', 'actor_user_id', 'actor_person_id', 'action_type',
    'subject_album_id', 'subject_event_id', 'subject_story_id', 'subject_person_id',
    'contribution_batch_id', 'photo_ids', 'created_at',
])]
class FamilyActivity extends Model
{
    use HasUlids;

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action_type' => FamilyActivityType::class,
            'photo_ids' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
