<?php

namespace Database\Factories;

use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Photo> */
class PhotoFactory extends Factory
{
    protected $model = Photo::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'family_space_id' => FamilySpace::factory(),
            'media_upload_id' => function (array $attributes): string {
                $mediaUploadId = (string) Str::ulid();
                $familySpaceId = (string) $attributes['family_space_id'];

                return MediaUpload::factory()->create([
                    'id' => $mediaUploadId,
                    'family_space_id' => $familySpaceId,
                    'state' => MediaUploadState::Ready,
                    'staging_object_key' => "families/{$familySpaceId}/media-staging/{$mediaUploadId}/original",
                    'canonical_object_key' => "families/{$familySpaceId}/media/{$mediaUploadId}/canonical.webp",
                    'canonical_mime_type' => 'image/webp',
                ])->id;
            },
            'created_by' => User::factory(),
            'visibility' => PhotoVisibility::FamilySpace,
            'caption' => fake()->sentence(4),
        ];
    }
}
