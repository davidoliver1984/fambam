<?php

namespace Tests\Feature;

use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\PerceptualHasher;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\DuplicateDecision;
use App\Models\FamilySpace;
use App\Models\MediaUpload;
use App\Models\PerceptualHash;
use App\Models\Photo;
use App\Models\User;
use App\Services\PerceptualSimilarityManager;
use App\Tenancy\TenantOperationContext;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerceptualSimilarityTest extends TestCase
{
    use RefreshDatabase;

    private PerceptualStorage $storage;

    private ConfigurablePerceptualHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new PerceptualStorage;
        $this->hasher = new ConfigurablePerceptualHasher;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(PerceptualHasher::class, $this->hasher);
    }

    public function test_versioned_hashes_generate_an_idempotent_tenant_scoped_candidate_at_the_calibrated_threshold(): void
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        [$firstPhoto, $firstUpload] = $this->photo($family, $user, 'first', '0000000000000000');
        [$secondPhoto, $secondUpload] = $this->photo($family, $user, 'second', '000000000003ffff');
        $manager = app(PerceptualSimilarityManager::class);
        $context = TenantOperationContext::forBackground($family->id, $user->id);

        $this->generate($manager, $context, $firstUpload);
        $this->generate($manager, $context, $secondUpload);
        $this->generate($manager, $context, $secondUpload);

        $this->assertDatabaseCount('perceptual_hashes', 2);
        $this->assertDatabaseHas('perceptual_hashes', [
            'media_upload_id' => $secondUpload->id,
            'algorithm' => 'dhash-luma-64',
            'processing_version' => 1,
            'hash_value' => '000000000003ffff',
        ]);
        [$low, $high] = $this->pair($firstPhoto->id, $secondPhoto->id);
        $this->assertDatabaseHas('duplicate_candidates', [
            'family_space_id' => $family->id,
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'source' => 'perceptual',
            'algorithm' => 'dhash-luma-64',
            'processing_version' => 1,
            'score' => 18,
        ]);
        $this->assertDatabaseCount('duplicate_candidates', 1);
    }

    public function test_distance_above_threshold_and_exact_checksum_pairs_do_not_create_perceptual_candidates(): void
    {
        $family = FamilySpace::factory()->create();
        $user = User::factory()->create();
        [, $first] = $this->photo($family, $user, 'first', '0000000000000000', 'shared-exact');
        [, $exact] = $this->photo($family, $user, 'exact', '0000000000000000', 'shared-exact');
        [, $far] = $this->photo($family, $user, 'far', '000000000007ffff');
        $manager = app(PerceptualSimilarityManager::class);
        $context = TenantOperationContext::forBackground($family->id, $user->id);

        foreach ([$first, $exact, $far] as $upload) {
            $this->generate($manager, $context, $upload);
        }

        $this->assertDatabaseCount('perceptual_hashes', 3);
        $this->assertDatabaseCount('duplicate_candidates', 0);
    }

    public function test_settled_decisions_deleted_photos_stale_assets_and_cross_tenant_context_are_suppressed(): void
    {
        $family = FamilySpace::factory()->create();
        $otherFamily = FamilySpace::factory()->create();
        $user = User::factory()->create();
        [$firstPhoto, $first] = $this->photo($family, $user, 'first', '0000000000000000');
        [$secondPhoto, $second] = $this->photo($family, $user, 'second', '0000000000000001');
        [, $foreign] = $this->photo($otherFamily, $user, 'foreign', '0000000000000000');
        [$low, $high] = $this->pair($firstPhoto->id, $secondPhoto->id);
        DuplicateDecision::query()->create([
            'family_space_id' => $family->id,
            'photo_low_id' => $low,
            'photo_high_id' => $high,
            'source' => 'perceptual_review',
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);
        $manager = app(PerceptualSimilarityManager::class);
        $context = TenantOperationContext::forBackground($family->id, $user->id);

        $this->generate($manager, $context, $first);
        $this->generate($manager, $context, $second);
        $this->generate($manager, $context, $foreign);
        $manager->generate(
            $context,
            $second->id,
            hash('sha256', 'stale-canonical'),
            'dhash-luma-64',
            1,
        );
        $firstPhoto->delete();
        PerceptualHash::query()->where('media_upload_id', $first->id)->delete();
        $this->generate($manager, $context, $first);

        $this->assertDatabaseCount('duplicate_candidates', 0);
        $this->assertDatabaseHas('perceptual_hashes', ['media_upload_id' => $second->id]);
        $this->assertDatabaseMissing('perceptual_hashes', ['media_upload_id' => $first->id]);
        $this->assertDatabaseMissing('perceptual_hashes', ['media_upload_id' => $foreign->id]);
    }

    /** @return array{Photo, MediaUpload} */
    private function photo(
        FamilySpace $family,
        User $user,
        string $label,
        string $hash,
        ?string $originalIdentity = null,
    ): array {
        $id = (string) Str::ulid();
        $bytes = "canonical-{$family->id}-{$label}";
        $key = "families/{$family->id}/media/{$id}/canonical.jpg";
        $upload = MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'original_sha256' => hash('sha256', $originalIdentity ?? "original-{$family->id}-{$label}"),
            'canonical_object_key' => $key,
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => hash('sha256', $bytes),
        ]);
        $this->storage->objects[$key] = $bytes;
        $this->hasher->hashes[$bytes] = $hash;
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $user->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);

        return [$photo, $upload];
    }

    private function generate(
        PerceptualSimilarityManager $manager,
        TenantOperationContext $context,
        MediaUpload $upload,
    ): void {
        $manager->generate(
            $context,
            $upload->id,
            (string) $upload->canonical_sha256,
            'dhash-luma-64',
            1,
        );
    }

    /** @return array{string, string} */
    private function pair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }
}

class ConfigurablePerceptualHasher implements PerceptualHasher
{
    /** @var array<string, string> */
    public array $hashes = [];

    public function hash(string $canonicalPath): string
    {
        return $this->hashes[(string) file_get_contents($canonicalPath)];
    }
}

class PerceptualStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by perceptual similarity tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return isset($this->objects[$key])
            ? new StoredObject(strlen($this->objects[$key]), hash('sha256', $this->objects[$key]))
            : null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        throw new \LogicException('Not used by perceptual similarity tests.');
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
