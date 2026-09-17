<?php

namespace App\Media;

use App\Models\Photo;
use RuntimeException;

final class PhotoPresentationResolver
{
    public function resolve(Photo $photo): PhotoPresentationAsset
    {
        $photo->loadMissing(['activeVersion', 'mediaUpload']);
        if ($photo->active_photo_version_id !== null) {
            $version = $photo->activeVersion;
            if ($version === null) {
                throw new RuntimeException('The active Photo version could not be found.');
            }

            return new PhotoPresentationAsset($version->derived_object_key, 'image/webp', 'webp', null, $version->id);
        }
        $upload = $photo->mediaUpload;
        if ($upload === null || $upload->canonical_object_key === null) {
            throw new RuntimeException('The Photo has no canonical presentation asset.');
        }
        $mime = (string) $upload->canonical_mime_type;
        $extension = match ($mime) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
            'image/tiff' => 'tiff', 'image/heic' => 'heic', 'image/heif' => 'heif',
            default => throw new RuntimeException('The Photo presentation has an unsupported format.'),
        };

        return new PhotoPresentationAsset($upload->canonical_object_key, $mime, $extension,
            $upload->canonical_sha256, null);
    }
}
