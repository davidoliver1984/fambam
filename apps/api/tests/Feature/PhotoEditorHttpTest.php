<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Media\GeneratedMediaVariant;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\PhotoEditPreview;
use App\Models\PhotoVersion;
use App\Models\User;
use App\PhotoEditing\EditRecipe;
use App\PhotoEditing\PhotoEditRenderer;
use App\PhotoEditing\RestoreAnalyzer;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoEditorHttpTest extends TestCase
{
    use RefreshDatabase;

    private TestPhotoStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new TestPhotoStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(PhotoEditRenderer::class, new TestPhotoEditRenderer);
        $this->app->instance(MediaDeliveryUrlSigner::class, new TestPhotoSigner);
        $this->app->instance(RestoreAnalyzer::class, new TestRestoreAnalyzer);
    }

    public function test_preview_apply_reedit_revert_and_authorized_version_delivery(): void
    {
        [$family, $owner, $photo] = $this->fixture('editor-main');
        $member = $this->member($family, FamilySpaceRole::Member);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'editor-other']);
        $outsider = $this->member($otherFamily, FamilySpaceRole::Owner);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $recipe = app(EditRecipe::class)->identity();
        $recipe['rotate_degrees'] = 90;
        $this->actingAs($owner)->postJson("{$base}/edit-previews", ['edit_recipe' => $recipe])
            ->assertCreated()->assertJsonPath('data.outcome', 'preview_ready');
        $this->assertDatabaseCount('photo_versions', 0);
        $previewId = (string) PhotoEditPreview::query()->firstOrFail()->id;
        $this->actingAs($member)->getJson("{$base}/edit-previews/{$previewId}/delivery")
            ->assertNotFound();
        $this->actingAs($owner)->getJson("{$base}/edit-previews/{$previewId}/delivery")
            ->assertOk()->assertJsonPath('data.asset', 'photo_edit_preview');
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$previewId}/apply")
            ->assertOk()->assertJsonPath('data.id', $previewId);
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$previewId}/apply")
            ->assertOk()->assertJsonPath('data.id', $previewId);
        $this->assertDatabaseCount('photo_versions', 1);
        $this->assertDatabaseCount('photo_edit_previews', 0);
        $this->assertSame($previewId, $photo->fresh()->active_photo_version_id);
        $this->actingAs($member)->getJson("{$base}/versions/{$previewId}/delivery")
            ->assertOk()->assertJsonPath('data.asset', 'photo_version');
        $this->actingAs($outsider)->getJson("/api/families/{$otherFamily->slug}/photos/{$photo->id}/versions/{$previewId}/delivery")
            ->assertNotFound();
        $second = $recipe;
        $second['adjustments']['brightness'] = 10;
        $this->actingAs($owner)->postJson("{$base}/edit-previews", ['edit_recipe' => $second])->assertCreated();
        $secondId = (string) PhotoEditPreview::query()->firstOrFail()->id;
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$secondId}/apply")->assertOk();
        $this->assertDatabaseCount('photo_versions', 2);
        $this->assertStringContainsString('rendered:canonical-pixels:', $this->storage->objects[
            PhotoVersion::query()->findOrFail($secondId)->derived_object_key
        ]);
        $this->assertSame($secondId, $photo->fresh()->active_photo_version_id);
        $this->actingAs($owner)->putJson("{$base}/active-version", ['photo_version_id' => null])
            ->assertOk()->assertJsonPath('data.active_photo_version_id', null);
        $this->assertNull($photo->fresh()->active_photo_version_id);
        $this->assertDatabaseCount('photo_versions', 2);
        $this->actingAs($owner)->putJson("{$base}/active-version", ['photo_version_id' => $previewId])
            ->assertOk()->assertJsonPath('data.active_photo_version_id', $previewId);
        $this->assertSame($previewId, $photo->fresh()->active_photo_version_id);
        $this->assertDatabaseHas('photo_versions', ['id' => $secondId, 'photo_id' => $photo->id]);
    }

    public function test_invalid_recipe_restore_no_improvement_and_restore_apply(): void
    {
        [$family, $owner, $photo] = $this->fixture('editor-restore');
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $bad = app(EditRecipe::class)->identity();
        $bad['filter'] = ['name' => 'generative_rebuild', 'intensity' => 100];
        $this->actingAs($owner)->postJson("{$base}/edit-previews", ['edit_recipe' => $bad])
            ->assertUnprocessable();
        $this->assertDatabaseCount('photo_versions', 0);
        TestRestoreAnalyzer::$improves = false;
        $this->actingAs($owner)->postJson("{$base}/restore-previews")
            ->assertOk()->assertJsonPath('data.outcome', 'no_improvement_found');
        $this->assertDatabaseCount('photo_edit_previews', 0);
        TestRestoreAnalyzer::$improves = true;
        $this->actingAs($owner)->postJson("{$base}/restore-previews")
            ->assertCreated()->assertJsonPath('data.restore.algorithm_version', 'restore-v1');
        $previewId = (string) PhotoEditPreview::query()->firstOrFail()->id;
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$previewId}/apply")
            ->assertOk()->assertJsonPath('data.restore.outcome', 'applied');
        $this->assertSame('restore-v1', PhotoVersion::query()->firstOrFail()->restore['algorithm_version']);
        TestRestoreAnalyzer::$invalid = true;
        $this->actingAs($owner)->postJson("{$base}/restore-previews")->assertUnprocessable();
        $this->assertDatabaseCount('photo_edit_previews', 0);
        TestRestoreAnalyzer::$invalid = false;
        TestRestoreAnalyzer::$improves = false;
    }

    public function test_expired_and_stale_previews_cannot_be_delivered_or_applied(): void
    {
        [$family, $owner, $photo] = $this->fixture('editor-stale');
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $recipe = app(EditRecipe::class)->identity();
        $this->actingAs($owner)->postJson("{$base}/edit-previews", ['edit_recipe' => $recipe])->assertCreated();
        $expired = PhotoEditPreview::query()->firstOrFail();
        $expired->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($owner)->getJson("{$base}/edit-previews/{$expired->id}/delivery")->assertNotFound();
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$expired->id}/apply")->assertNotFound();
        $this->actingAs($owner)->postJson("{$base}/edit-previews", ['edit_recipe' => $recipe])->assertCreated();
        $stale = PhotoEditPreview::query()->where('expires_at', '>', now())->firstOrFail();
        $stale->update(['base_photo_version_id' => '01M00000000000000000000000']);
        $this->actingAs($owner)->postJson("{$base}/edit-previews/{$stale->id}/apply")->assertStatus(409);
        $this->assertDatabaseCount('photo_versions', 0);
    }

    /** @return array{FamilySpace, User, Photo} */
    private function fixture(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $this->storage->objects[$photo->mediaUpload->canonical_object_key] = 'canonical-pixels';

        return [$family, $owner, $photo];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}

class TestPhotoStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by Photo editor tests.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        if (isset($this->objects[$key])) {
            throw new \LogicException('A final object may never be overwritten.');
        }
        $this->objects[$key] = file_get_contents($localPath);
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}

class TestPhotoEditRenderer implements PhotoEditRenderer
{
    public function render(string $canonicalPath, array $recipe, ?array $restore): GeneratedMediaVariant
    {
        $path = tempnam(sys_get_temp_dir(), 'photo-edit-test-');
        file_put_contents($path, 'rendered:'.file_get_contents($canonicalPath).':'.json_encode($recipe).':'.json_encode($restore));

        return new GeneratedMediaVariant($path, 'webp', 'image/webp', hash_file('sha256', $path), 100, 100, filesize($path));
    }
}

class TestRestoreAnalyzer extends RestoreAnalyzer
{
    public static bool $improves = false;

    public static bool $invalid = false;

    public function analyze(string $canonicalPath): ?array
    {
        return self::$improves ? ['schema_version' => 1, 'algorithm_version' => 'restore-v1',
            'processing_mode' => 'conservative', 'parameters' => [
                'white_balance_shift' => 0.0, 'exposure_adjustment' => 2.0,
                'saturation_recovery' => 0.0, 'denoise_strength' => 0.0, 'sharpen_strength' => 0.0],
            'outcome' => self::$invalid ? 'fabricated' : 'applied'] : null;
    }
}

class TestPhotoSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt, MediaSigningAudience $audience): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization('https://storage.test/'.rawurlencode($key),
            CarbonImmutable::instance($expiresAt));
    }
}
