<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Services\AlbumContributionFinalizer;
use App\Services\AlbumManager;
use App\Services\MediaRecoveryManager;
use App\Services\MediaVariantManager;
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

    public function test_album_metadata_tags_and_people_are_optional_tenant_scoped_and_mutable(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-metadata']);
        $other = FamilySpace::factory()->create(['slug' => 'other-album-metadata']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $outsider = Person::factory()->create(['family_space_id' => $other->id]);
        $path = '/api/families/album-metadata/albums';

        $this->actingAs($owner)->postJson($path, ['name' => 'Invalid', 'starts_on' => '2020-02-02',
            'ends_on' => '2020-02-01'])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->actingAs($owner)->postJson($path, ['name' => 'Other', 'person_ids' => [$outsider->id]])
            ->assertUnprocessable();

        $albumId = $this->actingAs($owner)->postJson($path, [
            'name' => 'School days', 'starts_on' => '2001-01-01', 'ends_on' => '2001-12-31',
            'location' => ' London ', 'tags' => ['Family', 'family'], 'person_ids' => [$person->id],
        ])->assertCreated()->assertJsonPath('data.starts_on', '2001-01-01')
            ->assertJsonPath('data.location', 'London')->json('data.id');
        $this->assertDatabaseCount('album_tag', 1);
        $this->assertDatabaseHas('album_people', ['album_id' => $albumId, 'person_id' => $person->id]);
        $this->actingAs($owner)->patchJson("{$path}/{$albumId}", ['ends_on' => '2000-12-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->actingAs($owner)->patchJson("{$path}/{$albumId}", [
            'starts_on' => null, 'ends_on' => null, 'location' => null, 'tags' => [], 'person_ids' => [],
        ])->assertOk()->assertJsonPath('data.starts_on', null)->assertJsonPath('data.location', null);
        $this->assertDatabaseCount('album_tag', 0);
        $this->assertDatabaseCount('album_people', 0);
    }

    public function test_cover_uses_an_authorized_member_photo_and_clears_on_removal_and_soft_delete(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-cover']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $first = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $second = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $albumId = $this->actingAs($owner)->postJson('/api/families/album-cover/albums', [
            'name' => 'Covered', 'cover_photo_id' => $first->id,
        ])->assertCreated()->assertJsonPath('data.cover.photo_id', $first->id)->json('data.id');
        $this->assertDatabaseHas('album_photos', ['album_id' => $albumId, 'photo_id' => $first->id]);
        $this->actingAs($owner)->putJson("/api/families/album-cover/albums/{$albumId}/cover", [
            'photo_id' => $second->id, 'focal_x' => 0.25, 'focal_y' => 0.75,
        ])->assertOk()->assertJsonPath('data.cover.photo_id', $second->id)
            ->assertJsonPath('data.cover.focal_x', 0.25);
        $this->actingAs($owner)->deleteJson("/api/families/album-cover/albums/{$albumId}/photos/{$second->id}")
            ->assertUnprocessable();
        $this->actingAs($owner)->deleteJson("/api/families/album-cover/albums/{$albumId}/photos/{$second->id}", [
            'confirm_cover_removal' => true,
        ])->assertNoContent();
        $this->assertNull(Album::findOrFail($albumId)->cover_photo_id);
        $this->actingAs($owner)->putJson("/api/families/album-cover/albums/{$albumId}/cover", [
            'photo_id' => $first->id,
        ])->assertOk();
        $this->actingAs($owner)->deleteJson("/api/families/album-cover/photos/{$first->id}")->assertNoContent();
        $this->assertNull(Album::findOrFail($albumId)->cover_photo_id);
        $this->actingAs($owner)->postJson("/api/families/album-cover/photos/{$first->id}/restore")->assertOk();
        $this->assertNull(Album::findOrFail($albumId)->cover_photo_id);
    }

    public function test_stale_cover_upload_cannot_replace_a_newer_choice_and_contributor_cannot_set_intent(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-cover-intent']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $membership] = $this->member($family, FamilySpaceRole::Contributor);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Cover intent', 'visibility' => AlbumVisibility::FamilySpace]);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => true,
            'granted_by' => $owner->id]);
        $this->actingAs($contributor)->postJson("/api/families/album-cover-intent/albums/{$album->id}/media-uploads", [
            'client_filename' => 'contribution.jpg', 'as_cover' => true,
        ], ['Idempotency-Key' => 'cover-contributor'])->assertCreated()
            ->assertJsonPath('data.cover_intent_accepted', false)
            ->assertJsonPath('data.cover_intent_reason', 'album_update_forbidden');
        $this->assertNull($album->refresh()->current_cover_intent_id);
        $this->assertDatabaseHas('media_uploads', ['family_space_id' => $family->id,
            'user_id' => $contributor->id, 'target_album_id' => $album->id]);

        $old = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        $new = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $album->id]);
        $album->update(['current_cover_intent_id' => $new->id]);
        $finalizer = app(AlbumContributionFinalizer::class);
        $context = new TenantOperationContext($family->id, $owner->id, 'album-cover-intent',
            TenantOperationContext::newTraceparent());
        $finalizer->finalize($old, $context);
        $this->assertSame($new->id, $album->refresh()->current_cover_intent_id);
        $this->assertNull($album->cover_photo_id);
        $finalizer->finalize($new, $context);
        $this->assertSame(Photo::query()->where('media_upload_id', $new->id)->firstOrFail()->id,
            $album->refresh()->cover_photo_id);
        $this->assertNull($album->current_cover_intent_id);
    }

    public function test_cover_upload_intent_is_idempotent_and_old_replay_cannot_resurrect_it(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'cover-upload-idempotency']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Cover choices']);
        $path = "/api/families/cover-upload-idempotency/albums/{$album->id}/media-uploads";
        $oldId = $this->actingAs($owner)->postJson($path, [
            'client_filename' => 'old.jpg', 'as_cover' => true,
        ], ['Idempotency-Key' => 'old-cover'])->assertCreated()->json('data.id');
        $this->assertSame($oldId, $album->refresh()->current_cover_intent_id);
        $newId = $this->actingAs($owner)->postJson($path, [
            'client_filename' => 'new.jpg', 'as_cover' => true,
        ], ['Idempotency-Key' => 'new-cover'])->assertCreated()->json('data.id');
        $this->assertSame($newId, $album->refresh()->current_cover_intent_id);
        $this->actingAs($owner)->postJson($path, [
            'client_filename' => 'old.jpg', 'as_cover' => true,
        ], ['Idempotency-Key' => 'old-cover'])->assertOk()->assertJsonPath('data.id', $oldId);
        $this->assertSame($newId, $album->refresh()->current_cover_intent_id);
        $this->actingAs($owner)->postJson($path, [
            'client_filename' => 'old.jpg', 'as_cover' => false,
        ], ['Idempotency-Key' => 'old-cover'])->assertUnprocessable();
    }

    public function test_cover_intent_fails_closed_when_management_authority_is_lost(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'cover-authority-revoked']);
        [$creator] = $this->member($family, FamilySpaceRole::Owner);
        [$manager, $membership] = $this->member($family, FamilySpaceRole::Administrator);
        $existing = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $creator->id]);
        $albumId = $this->actingAs($creator)->postJson('/api/families/cover-authority-revoked/albums', [
            'name' => 'A cover to preserve', 'cover_photo_id' => $existing->id,
        ])->assertCreated()->json('data.id');
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $manager->id,
            'state' => MediaUploadState::Ready, 'target_album_id' => $albumId]);
        Album::findOrFail($albumId)->update(['current_cover_intent_id' => $upload->id]);
        $membership->update(['role' => FamilySpaceRole::Member]);

        $context = new TenantOperationContext($family->id, $manager->id, 'cover-authority-revoked',
            TenantOperationContext::newTraceparent());
        app(AlbumContributionFinalizer::class)->finalize($upload, $context);

        $album = Album::findOrFail($albumId);
        $this->assertSame($existing->id, $album->cover_photo_id);
        $this->assertNull($album->current_cover_intent_id);
        $this->assertDatabaseHas('album_photos', ['album_id' => $albumId,
            'photo_id' => Photo::query()->where('media_upload_id', $upload->id)->firstOrFail()->id]);
    }

    public function test_failed_cover_intent_only_clears_its_own_pending_choice(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'cover-intent-failure']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $existing = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $albumId = $this->actingAs($owner)->postJson('/api/families/cover-intent-failure/albums', [
            'name' => 'Preserved', 'cover_photo_id' => $existing->id,
        ])->assertCreated()->json('data.id');
        $old = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id,
            'target_album_id' => $albumId]);
        $new = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id,
            'target_album_id' => $albumId]);
        $album = Album::findOrFail($albumId);
        $album->update(['current_cover_intent_id' => $new->id]);
        $manager = app(AlbumManager::class);
        $manager->clearCoverIntentIfCurrent($old);
        $this->assertSame($new->id, $album->refresh()->current_cover_intent_id);
        $manager->clearCoverIntentIfCurrent($new);
        $this->assertNull($album->refresh()->current_cover_intent_id);
        $this->assertSame($existing->id, $album->cover_photo_id);
    }

    public function test_processing_failure_clears_pending_cover_without_removing_existing_cover(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'cover-processing-failure']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $existing = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $albumId = $this->actingAs($owner)->postJson('/api/families/cover-processing-failure/albums', [
            'name' => 'Preserved', 'cover_photo_id' => $existing->id,
        ])->assertCreated()->json('data.id');
        $album = Album::findOrFail($albumId);
        $context = new TenantOperationContext($family->id, $owner->id, 'cover-processing-failure',
            TenantOperationContext::newTraceparent());
        $checksum = hash('sha256', 'failed cover');

        $variantUpload = MediaUpload::factory()->create(['family_space_id' => $family->id,
            'user_id' => $owner->id, 'state' => MediaUploadState::Processing,
            'target_album_id' => $albumId, 'canonical_sha256' => $checksum]);
        $album->update(['current_cover_intent_id' => $variantUpload->id]);
        app(MediaVariantManager::class)->markDegraded($context, $variantUpload->id, $checksum);
        $this->assertSame(MediaUploadState::Degraded, $variantUpload->refresh()->state);
        $this->assertNull($album->refresh()->current_cover_intent_id);
        $this->assertSame($existing->id, $album->cover_photo_id);

        $canonicalUpload = MediaUpload::factory()->create(['family_space_id' => $family->id,
            'user_id' => $owner->id, 'state' => MediaUploadState::Preserved,
            'target_album_id' => $albumId, 'original_sha256' => $checksum]);
        $album->update(['current_cover_intent_id' => $canonicalUpload->id]);
        app(MediaRecoveryManager::class)->markCanonicalDegraded($context, $canonicalUpload->id, $checksum);
        $this->assertSame(MediaUploadState::Degraded, $canonicalUpload->refresh()->state);
        $this->assertNull($album->refresh()->current_cover_intent_id);
        $this->assertSame($existing->id, $album->cover_photo_id);
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

        $this->actingAs($contributor)->getJson("/api/families/album-original/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('data.photos.0.media_upload_id', $photo->media_upload_id);
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

    public function test_detaching_an_event_album_resets_guest_participation(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-album-detach']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Reception',
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'event_id' => $event->id, 'name' => 'Guest photographs',
            'visibility' => AlbumVisibility::FamilySpace,
            'guest_participation' => 'contribute',
        ]);

        $this->actingAs($owner)->patchJson("/api/families/{$family->slug}/albums/{$album->id}", [
            'event_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.event_id', null)
            ->assertJsonPath('data.guest_participation', 'none');

        $this->assertNull($album->refresh()->event_id);
        $this->assertSame('none', $album->guest_participation->value);
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
    public function authorizeRead(string $key, string $responseContentType, DateTimeInterface $expiresAt, MediaSigningAudience $audience): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization('https://storage.test/'.rawurlencode($key), CarbonImmutable::instance($expiresAt));
    }
}
