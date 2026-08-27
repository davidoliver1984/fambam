<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaUploadDuplicateHold;
use App\Models\Photo;
use App\Models\User;
use App\Services\AlbumContributionFinalizer;
use App\Services\ExactDuplicateDetector;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExactDuplicateDetectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_creation_discloses_all_visible_matches_and_uses_the_selected_photo(): void
    {
        [$family, $member] = $this->family('exact-direct');
        $checksum = hash('sha256', 'same-original');
        $first = $this->photo($family, $member, $checksum, 'First copy');
        $second = $this->photo($family, $member, $checksum, 'Second copy');
        $candidate = $this->upload($family, $member, $checksum);

        $this->actingAs($member)->postJson('/api/families/exact-direct/photos', [
            'media_upload_id' => $candidate->id,
        ])->assertStatus(409)
            ->assertJsonPath('data.outcome', 'duplicate_detected')
            ->assertJsonCount(2, 'data.candidates')
            ->assertJsonFragment(['id' => $first->id])
            ->assertJsonFragment(['id' => $second->id]);

        $this->actingAs($member)->postJson('/api/families/exact-direct/photos', [
            'media_upload_id' => $candidate->id,
            'duplicate_resolution' => 'use_existing',
            'existing_photo_id' => $second->id,
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'existing_photo')
            ->assertJsonPath('data.photo.id', $second->id);

        $this->assertDatabaseMissing('photos', ['media_upload_id' => $candidate->id]);
        $this->assertSame(MediaUploadState::Ready, $candidate->refresh()->state);
    }

    public function test_create_new_records_every_disclosed_pair_but_not_an_invisible_match(): void
    {
        [$family, $member] = $this->family('exact-decisions');
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $checksum = hash('sha256', 'decision-original');
        $first = $this->photo($family, $member, $checksum, 'First visible');
        $second = $this->photo($family, $member, $checksum, 'Second visible');
        $hidden = $this->photo($family, $owner, $checksum, 'Private hidden', PhotoVisibility::Private);
        $candidate = $this->upload($family, $member, $checksum);

        $createdId = $this->actingAs($member)->postJson('/api/families/exact-decisions/photos', [
            'media_upload_id' => $candidate->id,
            'duplicate_resolution' => 'create_new',
            'disclosed_photo_ids' => [$first->id, $second->id],
        ])->assertCreated()->assertJsonPath('data.outcome', 'photo_created')->json('data.photo.id');

        foreach ([$first, $second] as $visible) {
            [$low, $high] = $this->pair($createdId, $visible->id);
            $this->assertDatabaseHas('duplicate_decisions', [
                'photo_low_id' => $low,
                'photo_high_id' => $high,
                'source' => 'exact_creation_choice',
            ]);
        }
        [$low, $high] = $this->pair($createdId, $hidden->id);
        $this->assertDatabaseMissing('duplicate_decisions', ['photo_low_id' => $low, 'photo_high_id' => $high]);
        $this->assertDatabaseCount('duplicate_decisions', 2);
    }

    public function test_invisible_and_cross_tenant_checksum_matches_are_indistinguishable_from_no_match(): void
    {
        [$family, $member] = $this->family('exact-isolation');
        $owner = $this->member($family, FamilySpaceRole::Owner);
        [$otherFamily, $otherMember] = $this->family('exact-other');
        $checksum = hash('sha256', 'isolated-original');
        $hidden = $this->photo($family, $owner, $checksum, 'Hidden', PhotoVisibility::Private);
        $foreign = $this->photo($otherFamily, $otherMember, $checksum, 'Other tenant');
        $candidate = $this->upload($family, $member, $checksum);

        $created = $this->actingAs($member)->postJson('/api/families/exact-isolation/photos', [
            'media_upload_id' => $candidate->id,
        ])->assertCreated()->json('data.photo.id');

        [$low, $high] = $this->pair($created, $hidden->id);
        $this->assertDatabaseHas('duplicate_candidates', [
            'family_space_id' => $family->id,
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'source' => 'exact',
        ]);
        $this->assertDatabaseMissing('duplicate_candidates', ['photo_id' => $foreign->id]);
        $this->assertDatabaseMissing('duplicate_candidates', ['candidate_photo_id' => $foreign->id]);
        $this->assertDatabaseCount('duplicate_candidates', 1);
        $this->assertDatabaseCount('duplicate_decisions', 0);
    }

    public function test_duplicate_decision_is_idempotent_and_suppresses_exact_candidate_generation(): void
    {
        [$family, $member] = $this->family('exact-suppression');
        $checksum = hash('sha256', 'suppressed-original');
        $first = $this->photo($family, $member, $checksum, 'First');
        $second = $this->photo($family, $member, $checksum, 'Second');
        $detector = app(ExactDuplicateDetector::class);

        $detector->recordSeparateDecision($first, $second, $member);
        $detector->recordSeparateDecision($second, $first, $member);
        $detector->generateCandidatesFor($first);

        $this->assertDatabaseCount('duplicate_decisions', 1);
        $this->assertDatabaseCount('duplicate_candidates', 0);
    }

    public function test_album_duplicate_hold_is_private_to_uploader_and_rechecks_contribution_authority(): void
    {
        [$family, $owner] = $this->family('exact-hold', FamilySpaceRole::Owner);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $membership = FamilySpaceMembership::query()->where('family_space_id', $family->id)
            ->where('user_id', $contributor->id)->sole();
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Contributions',
            'visibility' => AlbumVisibility::Selected,
        ]);
        $album->grants()->create([
            'family_space_id' => $family->id,
            'family_space_membership_id' => $membership->id,
            'can_view' => true,
            'can_contribute' => true,
            'granted_by' => $owner->id,
        ]);
        $checksum = hash('sha256', 'held-original');
        $existing = $this->photo($family, $owner, $checksum, 'Existing');
        $album->photos()->attach($existing->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
        ]);
        $upload = $this->upload($family, $contributor, $checksum, $album);

        app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
            $family->id,
            $contributor->id,
            'exact-hold-test',
            TenantOperationContext::newTraceparent(),
        ));

        $hold = MediaUploadDuplicateHold::query()->sole();
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $upload->id]);
        $this->actingAs($owner)->getJson('/api/families/exact-hold/media-upload-duplicate-holds')->assertJsonCount(0, 'data');
        $this->actingAs($contributor)->getJson('/api/families/exact-hold/media-upload-duplicate-holds')
            ->assertOk()->assertJsonPath('data.0.id', $hold->id);

        $album->grants()->delete();
        $this->actingAs($contributor)->postJson("/api/families/exact-hold/media-upload-duplicate-holds/{$hold->id}/resolve", [
            'resolution' => 'create_new',
            'disclosed_photo_ids' => [$existing->id],
        ])->assertForbidden();
        $this->assertNull($hold->refresh()->resolved_at);
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $upload->id]);

        $album->grants()->create([
            'family_space_id' => $family->id,
            'family_space_membership_id' => $membership->id,
            'can_view' => true,
            'can_contribute' => true,
            'granted_by' => $owner->id,
        ]);
        $createdId = $this->actingAs($contributor)
            ->postJson("/api/families/exact-hold/media-upload-duplicate-holds/{$hold->id}/resolve", [
                'resolution' => 'create_new',
                'disclosed_photo_ids' => [$existing->id],
            ])->assertOk()
            ->assertJsonPath('data.outcome', 'create_new')
            ->json('data.photo_id');
        $this->assertDatabaseHas('photos', ['id' => $createdId, 'media_upload_id' => $upload->id]);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id, 'photo_id' => $createdId]);
        [$low, $high] = $this->pair($createdId, $existing->id);
        $this->assertDatabaseHas('duplicate_decisions', ['photo_low_id' => $low, 'photo_high_id' => $high]);
        $this->assertNotNull($hold->refresh()->resolved_at);
    }

    public function test_guest_resolves_only_their_authorised_event_album_holds_without_general_photo_creation(): void
    {
        [$family, $owner] = $this->family('guest-exact-hold', FamilySpaceRole::Owner);
        $guest = $this->member($family, FamilySpaceRole::Guest);
        $otherGuest = $this->member($family, FamilySpaceRole::Guest);
        $membership = $this->membership($family, $guest);
        $otherMembership = $this->membership($family, $otherGuest);
        $event = $this->event($family, $owner, 'Guest contribution event');
        $album = $this->eventAlbum($family, $owner, $event, GuestParticipation::Contribute);
        $admission = $this->admit($family, $event, $membership);
        $this->admit($family, $event, $otherMembership);
        $checksum = hash('sha256', 'guest-held-original');
        $existing = $this->photo($family, $owner, $checksum, 'Existing event Photo');
        $album->photos()->attach($existing->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
        ]);

        $uploads = collect(['cancel', 'use_existing', 'create_new'])
            ->mapWithKeys(function (string $resolution) use ($family, $guest, $checksum, $album): array {
                $upload = $this->upload($family, $guest, $checksum, $album);
                app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
                    $family->id,
                    $guest->id,
                    "guest-hold-{$resolution}",
                    TenantOperationContext::newTraceparent(),
                ));

                return [$resolution => $upload];
            });
        $holds = MediaUploadDuplicateHold::query()->get()->keyBy('media_upload_id');
        $createHold = $holds->get($uploads['create_new']->id);
        $this->assertNotNull($createHold);

        $this->actingAs($otherGuest)
            ->postJson("/api/families/{$family->slug}/media-upload-duplicate-holds/{$createHold->id}/resolve", [
                'resolution' => 'cancel',
            ])->assertNotFound();
        $this->actingAs($guest)->postJson("/api/families/{$family->slug}/photos", [
            'media_upload_id' => $uploads['create_new']->id,
        ])->assertForbidden();

        $cancelHold = $holds->get($uploads['cancel']->id);
        $this->actingAs($guest)
            ->postJson("/api/families/{$family->slug}/media-upload-duplicate-holds/{$cancelHold->id}/resolve", [
                'resolution' => 'cancel',
            ])->assertOk()->assertJsonPath('data.outcome', 'cancel')->assertJsonPath('data.photo_id', null);
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $uploads['cancel']->id]);

        $useExistingHold = $holds->get($uploads['use_existing']->id);
        $this->actingAs($guest)
            ->postJson("/api/families/{$family->slug}/media-upload-duplicate-holds/{$useExistingHold->id}/resolve", [
                'resolution' => 'use_existing',
                'existing_photo_id' => $existing->id,
            ])->assertOk()->assertJsonPath('data.outcome', 'use_existing')
            ->assertJsonPath('data.photo_id', $existing->id);
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $uploads['use_existing']->id]);

        $admission->update(['revoked_at' => now(), 'revoked_by' => $owner->id]);
        $this->actingAs($guest)
            ->postJson("/api/families/{$family->slug}/media-upload-duplicate-holds/{$createHold->id}/resolve", [
                'resolution' => 'create_new',
                'disclosed_photo_ids' => [$existing->id],
            ])->assertForbidden();
        $this->assertNull($createHold->refresh()->resolved_at);
        $this->assertDatabaseMissing('photos', ['media_upload_id' => $uploads['create_new']->id]);

        $admission->update(['admitted_at' => now(), 'revoked_at' => null, 'revoked_by' => null]);
        $createdId = $this->actingAs($guest)
            ->postJson("/api/families/{$family->slug}/media-upload-duplicate-holds/{$createHold->id}/resolve", [
                'resolution' => 'create_new',
                'disclosed_photo_ids' => [$existing->id],
            ])->assertOk()->assertJsonPath('data.outcome', 'create_new')->json('data.photo_id');
        $this->assertDatabaseHas('photos', [
            'id' => $createdId,
            'media_upload_id' => $uploads['create_new']->id,
            'created_by' => $guest->id,
            'visibility' => PhotoVisibility::Private->value,
        ]);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id, 'photo_id' => $createdId]);
        [$low, $high] = $this->pair($createdId, $existing->id);
        $this->assertDatabaseHas('duplicate_decisions', ['photo_low_id' => $low, 'photo_high_id' => $high]);
    }

    public function test_event_album_contribution_does_not_disclose_an_invisible_checksum_match(): void
    {
        [$family, $owner] = $this->family('guest-invisible-exact', FamilySpaceRole::Owner);
        $guest = $this->member($family, FamilySpaceRole::Guest);
        $membership = $this->membership($family, $guest);
        $event = $this->event($family, $owner, 'Private duplicate event');
        $album = $this->eventAlbum($family, $owner, $event, GuestParticipation::Contribute);
        $this->admit($family, $event, $membership);
        $checksum = hash('sha256', 'invisible-held-original');
        $hidden = $this->photo(
            $family,
            $owner,
            $checksum,
            'Hidden exact match',
            PhotoVisibility::Private,
        );
        $upload = $this->upload($family, $guest, $checksum, $album);

        app(AlbumContributionFinalizer::class)->finalize($upload, new TenantOperationContext(
            $family->id,
            $guest->id,
            'guest-invisible-exact',
            TenantOperationContext::newTraceparent(),
        ));

        $created = Photo::query()->where('media_upload_id', $upload->id)->sole();
        $this->assertDatabaseCount('media_upload_duplicate_holds', 0);
        $this->assertDatabaseHas('album_photos', ['album_id' => $album->id, 'photo_id' => $created->id]);
        [$low, $high] = $this->pair($created->id, $hidden->id);
        $this->assertDatabaseHas('duplicate_candidates', [
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'source' => 'exact',
        ]);
        $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/media-upload-duplicate-holds")
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonMissing(['id' => $hidden->id]);
        $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/photos/{$created->id}")
            ->assertOk()->assertJsonMissing(['id' => $hidden->id]);
        $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/photos/{$hidden->id}")
            ->assertNotFound();
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug, FamilySpaceRole $role = FamilySpaceRole::Member): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->member($family, $role)];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function membership(FamilySpace $family, User $user): FamilySpaceMembership
    {
        return FamilySpaceMembership::query()
            ->where('family_space_id', $family->id)
            ->where('user_id', $user->id)
            ->sole();
    }

    private function event(FamilySpace $family, User $creator, string $name): FamilyEvent
    {
        return FamilyEvent::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => $name,
        ]);
    }

    private function eventAlbum(
        FamilySpace $family,
        User $creator,
        FamilyEvent $event,
        GuestParticipation $participation,
    ): Album {
        return Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => 'Event contributions',
            'visibility' => AlbumVisibility::FamilySpace,
            'event_id' => $event->id,
            'guest_participation' => $participation,
        ]);
    }

    private function admit(
        FamilySpace $family,
        FamilyEvent $event,
        FamilySpaceMembership $membership,
    ): EventAdmission {
        return EventAdmission::query()->create([
            'family_space_id' => $family->id,
            'event_id' => $event->id,
            'family_space_membership_id' => $membership->id,
            'admitted_at' => now(),
        ]);
    }

    private function upload(FamilySpace $family, User $user, string $checksum, ?Album $album = null): MediaUpload
    {
        $id = (string) Str::ulid();

        return MediaUpload::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'target_album_id' => $album?->id,
            'original_object_key' => "families/{$family->id}/media/{$id}/original.jpg",
            'original_sha256' => $checksum,
            'detected_mime_type' => 'image/jpeg',
            'canonical_object_key' => "families/{$family->id}/media/{$id}/canonical.jpg",
            'canonical_mime_type' => 'image/jpeg',
            'canonical_sha256' => hash('sha256', 'canonical-'.$id),
        ]);
    }

    private function photo(
        FamilySpace $family,
        User $user,
        string $checksum,
        string $caption,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
    ): Photo {
        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $this->upload($family, $user, $checksum)->id,
            'created_by' => $user->id,
            'caption' => $caption,
            'visibility' => $visibility,
        ]);
    }

    /** @return array{string, string} */
    private function pair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }
}
