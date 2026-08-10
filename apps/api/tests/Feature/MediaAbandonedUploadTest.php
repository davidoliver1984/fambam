<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Jobs\AbandonMediaUpload;
use App\Media\MediaObjectStorage;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\User;
use App\Services\MediaAbandonedUploadManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaAbandonedUploadTest extends TestCase
{
    use RefreshDatabase;

    private AbandonedUploadStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new AbandonedUploadStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
    }

    public function test_expired_initiated_upload_is_claimed_before_staging_cleanup_and_is_idempotent(): void
    {
        [$upload, $context] = $this->upload(MediaUploadState::Initiated, now()->subHours(25));
        $this->storage->objects[$upload->staging_object_key] = 'untrusted-staging-bytes';
        $job = new AbandonMediaUpload($context->toArray(), $upload->id);

        $this->assertSame("media-abandoned:{$upload->id}", $job->uniqueId());
        $job->handle(app(MediaAbandonedUploadManager::class));
        $job->handle(app(MediaAbandonedUploadManager::class));

        $this->assertSame(MediaUploadState::Abandoned, $upload->refresh()->state);
        $this->assertNotNull($upload->staging_deleted_at);
        $this->assertArrayNotHasKey($upload->staging_object_key, $this->storage->objects);
        $this->assertSame([$upload->staging_object_key], $this->storage->deletedKeys);
    }

    public function test_cleanup_failure_leaves_an_abandoned_row_discoverable_for_retry(): void
    {
        [$upload, $context] = $this->upload(MediaUploadState::Initiated, now()->subHours(25));
        $this->storage->failuresRemaining = 1;
        $manager = app(MediaAbandonedUploadManager::class);

        try {
            $manager->abandon($context, $upload->id);
            $this->fail('Storage failure was swallowed.');
        } catch (\RuntimeException) {
            $this->assertSame(MediaUploadState::Abandoned, $upload->refresh()->state);
            $this->assertNull($upload->staging_deleted_at);
        }

        $manager->abandon($context, $upload->id);
        $this->assertNotNull($upload->refresh()->staging_deleted_at);
        $this->assertSame([$upload->staging_object_key, $upload->staging_object_key], $this->storage->deletedKeys);
    }

    public function test_fresh_or_completed_uploads_are_never_abandoned(): void
    {
        [$fresh, $freshContext] = $this->upload(MediaUploadState::Initiated, now()->subHours(23));
        [$uploaded, $uploadedContext] = $this->upload(MediaUploadState::Uploaded, now()->subHours(25));
        $manager = app(MediaAbandonedUploadManager::class);

        $manager->abandon($freshContext, $fresh->id);
        $manager->abandon($uploadedContext, $uploaded->id);

        $this->assertSame(MediaUploadState::Initiated, $fresh->refresh()->state);
        $this->assertSame(MediaUploadState::Uploaded, $uploaded->refresh()->state);
        $this->assertSame([], $this->storage->deletedKeys);
    }

    /** @return array{MediaUpload, TenantOperationContext} */
    private function upload(MediaUploadState $state, DateTimeInterface $createdAt): array
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => $state,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return [$upload, TenantOperationContext::forBackground($family->id, $user->id)];
    }
}

class AbandonedUploadStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deletedKeys = [];

    public int $failuresRemaining = 0;

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        throw new \LogicException('Not used by abandoned-upload tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        throw new \LogicException('Not used by abandoned-upload tests.');
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        throw new \LogicException('Not used by abandoned-upload tests.');
    }

    public function delete(string $key): void
    {
        $this->deletedKeys[] = $key;
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException('Storage cleanup failed.');
        }
        unset($this->objects[$key]);
    }
}
