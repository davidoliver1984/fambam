<?php

namespace App\Models;

use App\Enums\MediaUploadState;
use Carbon\CarbonImmutable;
use Database\Factories\MediaUploadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property MediaUploadState $state
 * @property CarbonImmutable|null $uploaded_at
 */
#[Fillable([
    'family_space_id',
    'user_id',
    'state',
    'staging_object_key',
    'staging_deleted_at',
    'original_object_key',
    'quarantine_object_key',
    'original_sha256',
    'byte_size',
    'client_filename',
    'client_mime_type',
    'detected_mime_type',
    'pixel_width',
    'pixel_height',
    'original_orientation',
    'camera_make',
    'camera_model',
    'exif_capture_timestamp',
    'gps_latitude',
    'gps_longitude',
    'original_exif_base64',
    'original_icc_profile_base64',
    'canonical_object_key',
    'canonical_mime_type',
    'canonical_sha256',
    'upload_batch_id',
    'upload_method',
    'rejection_reason',
    'idempotency_key',
    'request_fingerprint',
    'correlation_id',
    'traceparent',
    'uploaded_at',
])]
class MediaUpload extends Model
{
    /** @use HasFactory<MediaUploadFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return HasMany<MediaVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(MediaVariant::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => MediaUploadState::class,
            'byte_size' => 'integer',
            'pixel_width' => 'integer',
            'pixel_height' => 'integer',
            'original_orientation' => 'integer',
            'gps_latitude' => 'decimal:7',
            'gps_longitude' => 'decimal:7',
            'uploaded_at' => 'immutable_datetime',
            'staging_deleted_at' => 'immutable_datetime',
        ];
    }
}
