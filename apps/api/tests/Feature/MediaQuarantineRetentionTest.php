<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\MediaQuarantineRetentionManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaQuarantineRetentionTest extends TestCase
{
    use RefreshDatabase;

    private QuarantineRetentionStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new QuarantineRetentionStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
    }

    public function test_expired_quarantine_bytes_are_purged_while_the_rejection_row_survives(): void
    {
        [$upload, $context] = $this->quarantinedUpload(now()->subDays(8));
        $key = (string) $upload->quarantine_object_key;
        $this->storage->objects[$key] = 'rejected bytes';

        $retention = app(MediaQuarantineRetentionManager::class);
        $retention->purge($context, $upload->id);
        $retention->purge($context, $upload->id);

        $upload->refresh();
        $this->assertSame(MediaUploadState::Quarantined, $upload->state);
        $this->assertSame('invalid_image', $upload->rejection_reason);
        $this->assertNull($upload->quarantine_object_key);
        $this->assertArrayNotHasKey($key, $this->storage->objects);
        $this->assertSame([$key], $this->storage->deletedKeys);
    }

    public function test_quarantine_bytes_inside_the_retention_window_are_not_purged(): void
    {
        [$upload, $context] = $this->quarantinedUpload(now()->subDays(6));
        $key = (string) $upload->quarantine_object_key;
        $this->storage->objects[$key] = 'rejected bytes';

        app(MediaQuarantineRetentionManager::class)->purge($context, $upload->id);

        $this->assertSame($key, $upload->refresh()->quarantine_object_key);
        $this->assertSame([], $this->storage->deletedKeys);
        $this->assertSame('rejected bytes', $this->storage->objects[$key]);
    }

    /** @return array{MediaUpload, TenantOperationContext} */
    private function quarantinedUpload(DateTimeInterface $updatedAt): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $id = (string) Str::ulid();
        $upload = MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Quarantined,
            'staging_object_key' => "families/{$family->id}/media-staging/{$id}/original",
            'quarantine_object_key' => "families/{$family->id}/quarantine/{$id}/original.png",
            'rejection_reason' => 'invalid_image',
            'updated_at' => $updatedAt,
        ]);

        return [
            $upload,
            TenantOperationContext::forBackground($family->id, $user->id),
        ];
    }
}

class QuarantineRetentionStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by quarantine-retention tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        throw new \LogicException('Not used by quarantine-retention tests.');
    }

    public function downloadTo(string $key, string $localPath): void
    {
        throw new \LogicException('Not used by quarantine-retention tests.');
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        throw new \LogicException('Not used by quarantine-retention tests.');
    }

    public function delete(string $key): void
    {
        $this->deletedKeys[] = $key;
        unset($this->objects[$key]);
    }
}
