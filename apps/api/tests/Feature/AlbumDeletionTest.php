<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilyExportScope;
use App\Enums\FamilyExportState;
use App\Enums\FamilySpaceRole;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\AuditEvent;
use App\Models\FamilyExport;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use App\Services\FamilyExportManager;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumDeletionTest extends TestCase
{
    use RefreshDatabase;

    private AlbumDeletionStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new AlbumDeletionStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(MediaDeliveryUrlSigner::class, new AlbumDeletionUrlSigner);
    }

    public function test_album_delete_is_authorized_hard_delete_that_preserves_photos_and_audit_history(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-delete']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$creator] = $this->member($family, FamilySpaceRole::Member);
        [$otherMember, $otherMembership] = $this->member($family, FamilySpaceRole::Member);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-album-delete']);
        [$outsider] = $this->member($otherFamily, FamilySpaceRole::Owner);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id]);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => 'A lasting photograph',
            'visibility' => AlbumVisibility::Selected,
            'cover_photo_id' => $photo->id,
            'cover_focal_x' => 0.5,
            'cover_focal_y' => 0.5,
        ]);
        AlbumPhoto::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'photo_id' => $photo->id,
            'position' => 1,
            'added_by' => $creator->id,
        ]);
        AlbumGrant::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'family_space_membership_id' => $otherMembership->id,
            'can_view' => true,
            'can_contribute' => false,
            'granted_by' => $creator->id,
        ]);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id,
            'label' => 'Family',
            'normalized_label' => 'family',
            'created_by' => $creator->id,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id]);
        $album->tags()->attach($tag->id, [
            'family_space_id' => $family->id,
            'added_by' => $creator->id,
            'created_at' => now(),
        ]);
        $album->people()->attach($person->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'added_by' => $creator->id,
            'created_at' => now(),
        ]);
        $photo->mediaUpload->update(['target_album_id' => $album->id]);
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $creator->id,
            'scope' => FamilyExportScope::Album,
            'state' => FamilyExportState::Processing,
            'object_key' => "families/{$family->id}/family-exports/album.zip",
            'album_id' => $album->id,
            'generation_started_at' => now(),
        ]);
        $path = "/api/families/{$family->slug}/albums/{$album->id}";

        $this->actingAs($otherMember)->deleteJson($path)->assertForbidden();
        $this->actingAs($outsider)->deleteJson($path)->assertNotFound();
        $this->assertDatabaseHas('albums', ['id' => $album->id]);

        $this->storage->failNextDelete = true;
        $this->actingAs($owner)->deleteJson($path)->assertNoContent();

        $this->assertDatabaseMissing('albums', ['id' => $album->id]);
        $this->assertDatabaseMissing('album_photos', ['album_id' => $album->id]);
        $this->assertDatabaseMissing('album_grants', ['album_id' => $album->id]);
        $this->assertDatabaseMissing('album_tag', ['album_id' => $album->id]);
        $this->assertDatabaseMissing('album_people', ['album_id' => $album->id]);
        $this->assertDatabaseHas('photos', ['id' => $photo->id]);
        $this->assertDatabaseHas('tags', ['id' => $tag->id]);
        $this->assertDatabaseHas('people', ['id' => $person->id]);
        $this->assertNull(MediaUpload::query()->findOrFail($photo->media_upload_id)->target_album_id);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'album.deleted',
            'subject_type' => Album::class,
            'subject_id' => $album->id,
        ]);
        $audit = AuditEvent::query()->where('action', 'album.deleted')->firstOrFail();
        $this->assertSame($album->id, $audit->metadata['album_id']);
        $export->refresh();
        $this->assertSame(FamilyExportState::Failed, $export->state);
        $this->assertSame('album_deleted', $export->failure_reason);
        $this->assertNotNull($export->cancelled_at);
        $this->assertNull($export->album_id);
        $this->assertSame([], $this->storage->deleted);

        $context = TenantOperationContext::forBackground($family->id, $creator->id);
        $manager = app(FamilyExportManager::class);
        $manager->cleanupDue($context, $export->id);
        $this->assertSame([$export->object_key], $this->storage->deleted);
        $this->assertNull($export->fresh()->storage_reconciled_at);
        $export->update(['generation_finished_at' => now()]);
        $manager->cleanupDue($context, $export->id);
        $this->assertNotNull($export->fresh()->storage_reconciled_at);
    }

    public function test_deletion_fence_blocks_new_album_exports(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-delete-fence']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Deletion underway',
            'deleting_at' => now(),
        ]);

        $this->actingAs($owner)
            ->postJson("/api/families/{$family->slug}/albums/{$album->id}/exports")
            ->assertNotFound();
    }

    public function test_album_payload_exposes_canonical_timestamps_creator_and_photo_count(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-list-read-model']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner, 'Album Creator');
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Read model',
        ]);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        AlbumPhoto::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'photo_id' => $photo->id,
            'position' => 1,
            'added_by' => $owner->id,
        ]);

        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/albums")
            ->assertOk()
            ->assertJsonPath('data.0.creator.id', $owner->id)
            ->assertJsonPath('data.0.creator.name', 'Album Creator')
            ->assertJsonPath('data.0.photo_count', 1)
            ->assertJsonPath('data.0.created_at', $album->created_at?->toAtomString())
            ->assertJsonPath('data.0.updated_at', $album->updated_at?->toAtomString());
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role, ?string $name = null): array
    {
        $user = User::factory()->create($name === null ? [] : ['name' => $name]);
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$user, $membership];
    }
}

final class AlbumDeletionUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt, MediaSigningAudience $audience): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization("https://storage.test/{$key}", CarbonImmutable::instance($expiresAt));
    }
}

final class AlbumDeletionStorage implements MediaObjectStorage
{
    public bool $failNextDelete = false;

    /** @var list<string> */
    public array $deleted = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \LogicException('Not used by this test.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        throw new \LogicException('Not used by this test.');
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        throw new \LogicException('Not used by this test.');
    }

    public function delete(string $key): void
    {
        if ($this->failNextDelete) {
            $this->failNextDelete = false;
            throw new \RuntimeException('Simulated temporary storage failure.');
        }
        $this->deleted[] = $key;
    }
}
