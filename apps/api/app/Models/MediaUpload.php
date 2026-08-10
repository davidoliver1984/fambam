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

/**
 * @property MediaUploadState $state
 * @property CarbonImmutable|null $uploaded_at
 */
#[Fillable([
    'family_space_id',
    'user_id',
    'state',
    'staging_object_key',
    'original_object_key',
    'original_sha256',
    'byte_size',
    'client_filename',
    'client_mime_type',
    'detected_mime_type',
    'canonical_object_key',
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'state' => MediaUploadState::class,
            'byte_size' => 'integer',
            'uploaded_at' => 'immutable_datetime',
        ];
    }
}
