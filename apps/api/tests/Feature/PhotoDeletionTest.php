<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new PhotoDeletionUrlSigner);
    }

    public function test_tombstone_hides_photo_albums_conversations_and_assets_then_restore_reestablishes_them(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-tombstone']);
        $creator = $this->member($family, FamilySpaceRole::Member);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $creator->id,
            'name' => 'Archive', 'visibility' => 'family_space']);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $creator->id]);
        $story = PhotoStory::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
            'author_id' => $creator->id, 'body' => 'Retained story']);
        $comment = PhotoComment::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
            'author_id' => $viewer->id, 'body' => 'Retained comment']);
        PhotoReaction::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
            'user_id' => $viewer->id, 'reaction' => 'love']);
        $base = "/api/families/photo-tombstone/photos/{$photo->id}";

        $this->actingAs($creator)->deleteJson($base)->assertNoContent();
        $this->assertSoftDeleted($photo);
        $this->actingAs($viewer)->getJson($base)->assertNotFound();
        $this->actingAs($viewer)->getJson('/api/families/photo-tombstone/photos')->assertJsonCount(0, 'data');
        $this->actingAs($viewer)->getJson("/api/families/photo-tombstone/albums/{$album->id}")
            ->assertOk()->assertJsonCount(0, 'data.photos');
        foreach (['canonical', 'original'] as $asset) {
            $this->actingAs($creator)->getJson("/api/families/photo-tombstone/media-uploads/{$photo->media_upload_id}/{$asset}")->assertForbidden();
        }
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id, 'photo_id' => $photo->id]);
        $this->assertDatabaseHas('photo_stories', ['id' => $story->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('photo_comments', ['id' => $comment->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('photo_reactions', ['photo_id' => $photo->id]);

        $this->actingAs($creator)->getJson('/api/families/photo-tombstone/photos/deleted')
            ->assertOk()->assertJsonPath('data.0.permissions.can_restore', true);
        $this->actingAs($creator)->postJson("{$base}/restore")->assertOk();
        $this->actingAs($viewer)->getJson($base)->assertOk();
        $this->actingAs($viewer)->getJson("/api/families/photo-tombstone/albums/{$album->id}")->assertJsonCount(1, 'data.photos');
        $this->actingAs($viewer)->getJson("/api/families/photo-tombstone/media-uploads/{$photo->media_upload_id}/canonical")->assertOk();
    }

    public function test_authority_uses_photo_creator_not_uploader_and_requires_current_membership(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'creator-authority']);
        $creator = $this->member($family, FamilySpaceRole::Member);
        $uploader = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id]);
        $photo->mediaUpload->update(['user_id' => $uploader->id]);
        $path = "/api/families/creator-authority/photos/{$photo->id}";

        $this->actingAs($uploader)->deleteJson($path)->assertForbidden();
        $this->actingAs($creator)->deleteJson($path)->assertNoContent();
        FamilySpaceMembership::query()->where('family_space_id', $family->id)->where('user_id', $creator->id)
            ->update(['state' => MembershipState::Removed, 'removed_at' => now()]);
        $this->actingAs($creator)->postJson("{$path}/restore")->assertNotFound();
        $this->assertSoftDeleted($photo);
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role]);

        return $user;
    }
}

class PhotoDeletionUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization('https://storage.test/'.rawurlencode($key), CarbonImmutable::instance($expiresAt));
    }
}
