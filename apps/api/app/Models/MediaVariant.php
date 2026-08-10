<?php

namespace App\Models;

use App\Enums\MediaVariantTransform;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property MediaVariantTransform $transform_name
 */
#[Fillable([
    'family_space_id',
    'media_upload_id',
    'transform_name',
    'processing_version',
    'object_key',
    'mime_type',
    'sha256',
    'pixel_width',
    'pixel_height',
    'byte_size',
])]
class MediaVariant extends Model
{
    use HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<MediaUpload, $this> */
    public function mediaUpload(): BelongsTo
    {
        return $this->belongsTo(MediaUpload::class);
    }

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'transform_name' => MediaVariantTransform::class,
            'processing_version' => 'integer',
            'pixel_width' => 'integer',
            'pixel_height' => 'integer',
            'byte_size' => 'integer',
        ];
    }
}
