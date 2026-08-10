<?php

namespace Database\Factories;

use App\Enums\MediaUploadState;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<MediaUpload> */
class MediaUploadFactory extends Factory
{
    protected $model = MediaUpload::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $id = (string) Str::ulid();
        $familySpaceId = (string) Str::ulid();

        return [
            'id' => $id,
            'family_space_id' => FamilySpace::factory()->state(['id' => $familySpaceId]),
            'user_id' => User::factory(),
            'state' => MediaUploadState::Initiated,
            'staging_object_key' => "families/{$familySpaceId}/media-staging/{$id}/original",
            'client_filename' => 'family-photo.jpg',
            'client_mime_type' => 'image/jpeg',
            'upload_method' => 'single',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'factory'),
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
        ];
    }
}
