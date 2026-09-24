<?php

namespace App\Models;

use App\Enums\NotificationCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property string|null $story_comment_id
 * @property string|null $family_export_id
 * @property string|null $event_id
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property-read Photo|null $photo
 * @property-read Album|null $album
 * @property-read Story|null $story
 * @property-read Person|null $person
 * @property-read FamilyEvent|null $event
 * @property-read FamilyExport|null $familyExport
 */
#[Fillable(['family_space_id', 'recipient_user_id', 'category', 'source_action_id', 'photo_id', 'album_id', 'story_id', 'person_id', 'comment_id', 'story_comment_id', 'family_export_id', 'event_id', 'read_at'])]
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

    /** @return BelongsTo<Photo, $this> */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(Photo::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function album(): BelongsTo
    {
        return $this->belongsTo(Album::class);
    }

    /** @return BelongsTo<Story, $this> */
    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    /** @return BelongsTo<Person, $this> */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /** @return BelongsTo<FamilyEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(FamilyEvent::class);
    }

    /** @return BelongsTo<FamilyExport, $this> */
    public function familyExport(): BelongsTo
    {
        return $this->belongsTo(FamilyExport::class);
    }
}
