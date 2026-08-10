<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Jobs\GeneratePresentationMediaVariants;
use App\Media\CanonicalImageGenerator;
use App\Media\ExtractedMediaMetadata;
use App\Media\GeneratedCanonical;
use App\Media\MediaMetadataExtractor;
use App\Media\MediaObjectCollision;
use App\Media\MediaObjectStorage;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\MediaCanonicalManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private CanonicalStorage $storage;

    private CanonicalMetadataExtractor $metadata;

    private CanonicalGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->storage = new CanonicalStorage;
        $this->metadata = new CanonicalMetadataExtractor;
        $this->generator = new CanonicalGenerator;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(MediaMetadataExtractor::class, $this->metadata);
        $this->app->instance(CanonicalImageGenerator::class, $this->generator);
    }

    public function test_it_extracts_private_metadata_and_finalises_a_regenerable_canonical(): void
    {
        [$upload, $context, $original] = $this->preservedUpload();

        $manager = app(MediaCanonicalManager::class);
        $manager->generate($context, $upload->id, hash('sha256', $original));
        $manager->generate($context, $upload->id, hash('sha256', $original));

        $upload->refresh();
        $canonicalKey = "families/{$upload->family_space_id}/media/{$upload->id}/canonical.jpg";
        $this->assertSame(MediaUploadState::Processing, $upload->state);
        $this->assertSame(1200, $upload->pixel_width);
        $this->assertSame(800, $upload->pixel_height);
        $this->assertSame(6, $upload->original_orientation);
        $this->assertSame('Archive Camera Co', $upload->camera_make);
        $this->assertSame('Scanner 1987', $upload->camera_model);
        $this->assertSame('1987:06:01 12:30:00+01:00', $upload->exif_capture_timestamp);
        $this->assertSame('51.5014000', $upload->gps_latitude);
        $this->assertSame('-0.1419000', $upload->gps_longitude);
        $this->assertSame("raw-exif\0bytes", base64_decode((string) $upload->original_exif_base64, true));
        $this->assertSame("raw-icc\0bytes", base64_decode((string) $upload->original_icc_profile_base64, true));
        $this->assertSame($canonicalKey, $upload->canonical_object_key);
        $this->assertSame('image/jpeg', $upload->canonical_mime_type);
        $this->assertSame(hash('sha256', 'canonical-bytes'), $upload->canonical_sha256);
        $this->assertSame($original, $this->storage->objects[(string) $upload->original_object_key]);
        $this->assertSame('canonical-bytes', $this->storage->objects[$canonicalKey]);
        $this->assertSame(1, $this->metadata->calls);
        $this->assertSame(1, $this->generator->calls);
        Queue::assertPushed(GeneratePresentationMediaVariants::class, function (
            GeneratePresentationMediaVariants $job,
        ) use ($upload): bool {
            return $job->mediaUploadId === $upload->id
                && $job->canonicalSha256 === hash('sha256', 'canonical-bytes')
                && $job->processingVersion === 1;
        });
    }

    public function test_stale_source_identity_and_final_key_collisions_fail_closed(): void
    {
        [$upload, $context, $original] = $this->preservedUpload();
        $manager = app(MediaCanonicalManager::class);

        $manager->generate($context, $upload->id, hash('sha256', 'different'));
        $this->assertSame(0, $this->metadata->calls);
        $this->assertSame(MediaUploadState::Preserved, $upload->refresh()->state);

        $key = "families/{$upload->family_space_id}/media/{$upload->id}/canonical.jpg";
        $this->storage->objects[$key] = 'existing-canonical';
        $this->storage->checksums[$key] = hash('sha256', 'existing-canonical');

        $this->expectException(MediaObjectCollision::class);
        try {
            $manager->generate($context, $upload->id, hash('sha256', $original));
        } finally {
            $this->assertSame('existing-canonical', $this->storage->objects[$key]);
            $this->assertSame(MediaUploadState::Preserved, $upload->refresh()->state);
        }
    }

    /** @return array{MediaUpload, TenantOperationContext, string} */
    private function preservedUpload(): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $original = 'immutable-original-with-private-metadata';
        $originalKey = "families/{$family->id}/media/{$id}/original.jpg";
        $upload = MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Preserved,
            'original_object_key' => $originalKey,
            'original_sha256' => hash('sha256', $original),
        ]);
        $this->storage->objects[$originalKey] = $original;
        $this->storage->checksums[$originalKey] = hash('sha256', $original);

        return [
            $upload,
            TenantOperationContext::forBackground($family->id, $user->id),
            $original,
        ];
    }
}

class CanonicalStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $checksums = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        throw new \LogicException('Not used by canonical tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return isset($this->objects[$key])
            ? new StoredObject(strlen($this->objects[$key]), $this->checksums[$key] ?? null)
            : null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $bytes = (string) file_get_contents($localPath);
        if (isset($this->objects[$key])) {
            if ($this->objects[$key] === $bytes && hash_equals($this->checksums[$key] ?? '', $sha256)) {
                return;
            }

            throw new MediaObjectCollision('Conflicting canonical object.');
        }
        $this->objects[$key] = $bytes;
        $this->checksums[$key] = $sha256;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key], $this->checksums[$key]);
    }
}

class CanonicalMetadataExtractor implements MediaMetadataExtractor
{
    public int $calls = 0;

    public function extract(string $path): ExtractedMediaMetadata
    {
        $this->calls++;

        return new ExtractedMediaMetadata(
            1200,
            800,
            6,
            'Archive Camera Co',
            'Scanner 1987',
            '1987:06:01 12:30:00+01:00',
            '51.5014000',
            '-0.1419000',
            "raw-exif\0bytes",
            "raw-icc\0bytes",
        );
    }
}

class CanonicalGenerator implements CanonicalImageGenerator
{
    public int $calls = 0;

    public function generate(string $sourcePath): GeneratedCanonical
    {
        $this->calls++;
        $path = tempnam(sys_get_temp_dir(), 'canonical-test-');
        if ($path === false) {
            throw new \RuntimeException('Temporary canonical fixture failed.');
        }
        file_put_contents($path, 'canonical-bytes');

        return new GeneratedCanonical(
            $path,
            'jpg',
            'image/jpeg',
            hash('sha256', 'canonical-bytes'),
        );
    }
}
