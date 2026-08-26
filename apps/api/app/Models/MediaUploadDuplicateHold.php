<?php

namespace App\Models;

use App\Enums\DuplicateResolution;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $resolved_at
 * @property-read MediaUpload $mediaUpload
 * @property-read Album $targetAlbum
 */
#[Fillable(['family_space_id', 'media_upload_id', 'target_album_id', 'detected_at', 'resolution', 'chosen_photo_id', 'resolved_by', 'resolved_at'])]
class MediaUploadDuplicateHold extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<MediaUpload, $this> */
    public function mediaUpload(): BelongsTo
    {
        return $this->belongsTo(MediaUpload::class);
    }

    /** @return BelongsTo<Album, $this> */
    public function targetAlbum(): BelongsTo
    {
        return $this->belongsTo(Album::class, 'target_album_id');
    }

    protected function casts(): array
    {
        return [
            'detected_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'resolution' => DuplicateResolution::class,
        ];
    }
}
