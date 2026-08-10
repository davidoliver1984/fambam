<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Jobs\GeneratePresentationMediaVariants;
use App\Media\GeneratedMediaVariant;
use App\Media\MediaObjectCollision;
use App\Media\MediaObjectStorage;
use App\Media\PresentationVariantGenerator;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\User;
use App\Services\MediaVariantManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaVariantTest extends TestCase
{
    use RefreshDatabase;

    private VariantStorage $storage;

    private FakePresentationVariantGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new VariantStorage;
        $this->generator = new FakePresentationVariantGenerator;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(PresentationVariantGenerator::class, $this->generator);
    }

    public function test_it_generates_the_fixed_versioned_set_and_is_database_idempotent(): void
    {
        [$upload, $context, $canonical] = $this->processingUpload();
        $manager = app(MediaVariantManager::class);

        $manager->generate($context, $upload->id, hash('sha256', $canonical), 1);
        $manager->generate($context, $upload->id, hash('sha256', $canonical), 1);

        $upload->refresh();
        $variants = MediaVariant::query()->orderBy('transform_name')->get();
        $this->assertSame(MediaUploadState::Ready, $upload->state);
        $this->assertCount(3, $variants);
        $this->assertSame(['card', 'display', 'thumbnail'], $variants->pluck('transform_name')->map->value->all());
        $this->assertSame([1], $variants->pluck('processing_version')->unique()->values()->all());
        $this->assertSame(['image/webp'], $variants->pluck('mime_type')->unique()->values()->all());
        $this->assertSame('immutable-original', $this->storage->objects[(string) $upload->original_object_key]);
        $this->assertSame($canonical, $this->storage->objects[(string) $upload->canonical_object_key]);
        $this->assertSame(6, $this->generator->calls);
    }

    public function test_ready_variants_are_disposable_and_regenerable_without_new_rows(): void
    {
        [$upload, $context, $canonical] = $this->processingUpload();
        $manager = app(MediaVariantManager::class);
        $checksum = hash('sha256', $canonical);
        $manager->generate($context, $upload->id, $checksum, 1);

        $thumbnail = MediaVariant::query()->where('transform_name', 'thumbnail')->firstOrFail();
        $this->storage->delete($thumbnail->object_key);
        $manager->generate($context, $upload->id, $checksum, 1);

        $this->assertArrayHasKey($thumbnail->object_key, $this->storage->objects);
        $this->assertSame(3, MediaVariant::query()->count());
        $this->assertSame(MediaUploadState::Ready, $upload->refresh()->state);
    }

    public function test_stale_identity_and_write_once_collisions_cannot_replace_variant_bytes(): void
    {
        [$upload, $context, $canonical] = $this->processingUpload();
        $manager = app(MediaVariantManager::class);

        $manager->generate($context, $upload->id, hash('sha256', 'stale'), 1);
        $this->assertSame(0, $this->generator->calls);
        $this->assertSame(MediaUploadState::Processing, $upload->refresh()->state);

        $key = "families/{$upload->family_space_id}/media/{$upload->id}/variants/thumbnail.v1.webp";
        $this->storage->objects[$key] = 'existing-variant';
        $this->storage->checksums[$key] = hash('sha256', 'existing-variant');

        $this->expectException(MediaObjectCollision::class);
        try {
            $manager->generate($context, $upload->id, hash('sha256', $canonical), 1);
        } finally {
            $this->assertSame('existing-variant', $this->storage->objects[$key]);
            $this->assertSame(MediaUploadState::Processing, $upload->refresh()->state);
            $this->assertSame(0, MediaVariant::query()->count());
        }
    }

    public function test_exhausted_variant_job_marks_only_the_matching_processing_upload_degraded(): void
    {
        [$upload, $context, $canonical] = $this->processingUpload();
        $originalKey = (string) $upload->original_object_key;
        $canonicalKey = (string) $upload->canonical_object_key;
        $staleJob = new GeneratePresentationMediaVariants(
            $context->toArray(),
            $upload->id,
            hash('sha256', 'stale-canonical'),
            1,
        );
        $staleJob->failed(new \RuntimeException('stale worker exhausted'));
        $this->assertSame(MediaUploadState::Processing, $upload->refresh()->state);

        $job = new GeneratePresentationMediaVariants(
            $context->toArray(),
            $upload->id,
            hash('sha256', $canonical),
            1,
        );
        $this->assertSame(
            "variants:{$upload->id}:".hash('sha256', $canonical).':1',
            $job->uniqueId(),
        );

        $job->failed(new \RuntimeException('worker exhausted'));

        $this->assertSame(MediaUploadState::Degraded, $upload->refresh()->state);
        $this->assertSame('immutable-original', $this->storage->objects[$originalKey]);
        $this->assertSame($canonical, $this->storage->objects[$canonicalKey]);
    }

    /** @return array{MediaUpload, TenantOperationContext, string} */
    private function processingUpload(): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $original = 'immutable-original';
        $canonical = 'metadata-stripped-canonical';
        $originalKey = "families/{$family->id}/media/{$id}/original.jpg";
        $canonicalKey = "families/{$family->id}/media/{$id}/canonical.jpg";
        $upload = MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Processing,
            'original_object_key' => $originalKey,
            'original_sha256' => hash('sha256', $original),
            'canonical_object_key' => $canonicalKey,
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => hash('sha256', $canonical),
        ]);
        $this->storage->objects[$originalKey] = $original;
        $this->storage->checksums[$originalKey] = hash('sha256', $original);
        $this->storage->objects[$canonicalKey] = $canonical;
        $this->storage->checksums[$canonicalKey] = hash('sha256', $canonical);

        return [$upload, TenantOperationContext::forBackground($family->id, $user->id), $canonical];
    }
}

class VariantStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $checksums = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        throw new \LogicException('Not used by variant tests.');
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

            throw new MediaObjectCollision('Conflicting presentation variant.');
        }

        $this->objects[$key] = $bytes;
        $this->checksums[$key] = $sha256;
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key], $this->checksums[$key]);
    }
}

class FakePresentationVariantGenerator implements PresentationVariantGenerator
{
    public int $calls = 0;

    public function generate(string $canonicalPath, MediaVariantTransform $transform): GeneratedMediaVariant
    {
        $this->calls++;
        $path = tempnam(sys_get_temp_dir(), 'variant-test-');
        if ($path === false) {
            throw new \RuntimeException('Temporary variant fixture failed.');
        }
        $bytes = "{$transform->value}-variant-v1";
        file_put_contents($path, $bytes);
        $dimensions = $transform->dimensions();

        return new GeneratedMediaVariant(
            $path,
            'webp',
            'image/webp',
            hash('sha256', $bytes),
            $dimensions['width'],
            $dimensions['height'],
            strlen($bytes),
        );
    }
}
