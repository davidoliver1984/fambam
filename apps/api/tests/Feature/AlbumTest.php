<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\User;
use App\Services\AlbumContributionFinalizer;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new AlbumTestMediaDeliveryUrlSigner);
    }

    public function test_selected_album_grants_are_live_and_private_albums_reject_grants(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-grants']);
        [$owner, $ownerMembership] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $contributorMembership] = $this->member($family, FamilySpaceRole::Contributor);

        $albumId = $this->actingAs($owner)->postJson('/api/families/album-grants/albums', [
            'name' => 'Selected memories', 'visibility' => AlbumVisibility::Selected->value,
        ])->assertCreated()->json('data.id');

        $this->actingAs($contributor)->getJson("/api/families/album-grants/albums/{$albumId}")->assertNotFound();
        $this->actingAs($owner)->putJson("/api/families/album-grants/albums/{$albumId}/grants", [
            'membership_id' => $contributorMembership->id, 'can_view' => true, 'can_contribute' => true,
        ])->assertCreated();
        $this->actingAs($contributor)->getJson("/api/families/album-grants/albums/{$albumId}")
            ->assertOk()->assertJsonPath('data.permissions.can_contribute', true);
        $this->actingAs($owner)->deleteJson("/api/families/album-grants/albums/{$albumId}/grants/{$contributorMembership->id}")->assertNoContent();
        $this->actingAs($contributor)->getJson("/api/families/album-grants/albums/{$albumId}")->assertNotFound();

        $privateId = $this->actingAs($owner)->postJson('/api/families/album-grants/albums', [
            'name' => 'Private', 'visibility' => AlbumVisibility::Private->value,
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->putJson("/api/families/album-grants/albums/{$privateId}/grants", [
            'membership_id' => $contributorMembership->id, 'can_view' => true, 'can_contribute' => false,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('album_grants', 0);
        $this->assertNotSame($ownerMembership->id, $contributorMembership->id);
    }

    public function test_private_photo_widening_requires_confirmation_and_live_removal_narrows_again(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-widening']);
        [$creator] = $this->member($family, FamilySpaceRole::Member);
        [$viewer] = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id,
            'visibility' => PhotoVisibility::Private]);
        $albumId = $this->actingAs($creator)->postJson('/api/families/album-widening/albums', [
            'name' => 'Everyone', 'visibility' => AlbumVisibility::FamilySpace->value,
        ])->assertCreated()->json('data.id');

        $path = "/api/families/album-widening/albums/{$albumId}/photos";
        $this->actingAs($creator)->postJson($path, ['photo_id' => $photo->id])
            ->assertUnprocessable()->assertJsonValidationErrors('album');
        $this->actingAs($creator)->postJson($path, ['photo_id' => $photo->id, 'confirm_visibility_widening' => true])->assertCreated();
        $this->actingAs($viewer)->getJson("/api/families/album-widening/photos/{$photo->id}")->assertOk();
        $this->actingAs($creator)->deleteJson("{$path}/{$photo->id}")->assertNoContent();
        $this->actingAs($viewer)->getJson("/api/families/album-widening/photos/{$photo->id}")->assertNotFound();
        $this->assertSame(PhotoVisibility::Private, $photo->refresh()->visibility);
    }

    public function test_contributor_album_access_never_confers_original_download(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-original']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $membership] = $this->member($family, FamilySpaceRole::Contributor);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Contributors', 'visibility' => AlbumVisibility::Selected]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id]);

        $this->actingAs($contributor)->getJson("/api/families/album-original/photos/{$photo->id}")->assertOk();
        $this->actingAs($contributor)->getJson("/api/families/album-original/media-uploads/{$photo->media_upload_id}/canonical")->assertOk();
        $this->actingAs($contributor)->getJson("/api/families/album-original/media-uploads/{$photo->media_upload_id}/original")->assertForbidden();
    }

    public function test_guest_album_grant_is_ineffective_across_album_photo_media_and_upload_paths(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'guest-album-grant']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$guest, $guestMembership] = $this->member($family, FamilySpaceRole::Guest);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Selected memories', 'visibility' => AlbumVisibility::Selected]);

        $this->actingAs($owner)->putJson("/api/families/guest-album-grant/albums/{$album->id}/grants", [
            'membership_id' => $guestMembership->id, 'can_view' => true, 'can_contribute' => true,
        ])->assertUnprocessable();

        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $guestMembership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id]);

        $this->actingAs($guest)->getJson("/api/families/guest-album-grant/albums/{$album->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("/api/families/guest-album-grant/photos/{$photo->id}")->assertNotFound();
        $this->actingAs($guest)->getJson("/api/families/guest-album-grant/media-uploads/{$photo->media_upload_id}/canonical")->assertForbidden();
        $this->actingAs($guest)->postJson("/api/families/guest-album-grant/albums/{$album->id}/uploads", [
            'filename' => 'guest.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1024,
        ], ['Idempotency-Key' => 'guest-album-upload'])->assertNotFound();

        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $guest->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
            $family->id,
            $guest->id,
            'guest-album-finalization',
            TenantOperationContext::newTraceparent(),
        ));
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $upload->id]);
    }

    public function test_album_scoped_contributor_upload_creates_one_private_attached_photo_idempotently(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-upload']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $membership] = $this->member($family, FamilySpaceRole::Contributor);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Contributions', 'visibility' => AlbumVisibility::Selected]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $contributor->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        $context = new TenantOperationContext($family->id, $contributor->id, 'album-upload-test',
            TenantOperationContext::newTraceparent());

        $finalizer = app(AlbumContributionFinalizer::class);
        $finalizer->finalize($upload, $context);
        $finalizer->finalize($upload, $context);

        $photo = Photo::query()->where('media_upload_id', $upload->id)->firstOrFail();
        $this->assertSame(PhotoVisibility::Private, $photo->visibility);
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id, 'photo_id' => $photo->id]);
    }

    public function test_contributor_grant_applies_to_direct_and_uploaded_contributions_on_family_space_album(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'family-space-contributions']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $membership] = $this->member($family, FamilySpaceRole::Contributor);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Family contributions', 'visibility' => AlbumVisibility::FamilySpace]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $existingPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace]);

        $this->actingAs($contributor)->postJson("/api/families/family-space-contributions/albums/{$album->id}/photos", [
            'photo_id' => $existingPhoto->id,
        ])->assertCreated();

        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $contributor->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
            $family->id,
            $contributor->id,
            'family-space-album-finalization',
            TenantOperationContext::newTraceparent(),
        ));

        $uploadedPhoto = Photo::query()->where('media_upload_id', $upload->id)->firstOrFail();
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id,
            'photo_id' => $existingPhoto->id, 'position' => 1]);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id,
            'photo_id' => $uploadedPhoto->id, 'position' => 2]);
    }

    public function test_event_album_reuses_member_and_contributor_contribution_paths(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-contributions']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        [$contributor, $contributorMembership] = $this->member($family, FamilySpaceRole::Contributor);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Wedding']);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'event_id' => $event->id, 'name' => 'Reception', 'visibility' => AlbumVisibility::FamilySpace]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $contributorMembership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);

        $memberPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $this->actingAs($member)->postJson("/api/families/event-contributions/albums/{$album->id}/photos", [
            'photo_id' => $memberPhoto->id,
        ])->assertCreated();

        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $contributor->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
            $family->id,
            $contributor->id,
            'event-album-finalization',
            TenantOperationContext::newTraceparent(),
        ));

        $contributedPhoto = Photo::query()->where('media_upload_id', $upload->id)->firstOrFail();
        $this->assertSame(PhotoVisibility::Private, $contributedPhoto->visibility);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id,
            'photo_id' => $memberPhoto->id, 'position' => 1]);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id,
            'photo_id' => $contributedPhoto->id, 'position' => 2]);
        $this->assertSame($event->id, $album->refresh()->event_id);
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role]);

        return [$user, $membership];
    }
}

class AlbumTestMediaDeliveryUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization('https://storage.test/'.rawurlencode($key), CarbonImmutable::instance($expiresAt));
    }
}
