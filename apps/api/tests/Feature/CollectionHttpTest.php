<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\DatePrecision;
use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Enums\PersonProposalStatus;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CollectionHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_collections_are_private_to_their_owner_for_every_role(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'collection-private']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$administrator] = $this->member($family, FamilySpaceRole::Administrator);
        [$guest] = $this->member($family, FamilySpaceRole::Guest);
        $base = "/api/families/{$family->slug}/collections";
        $id = $this->actingAs($guest)->postJson($base, ['name' => 'My finds'])
            ->assertCreated()->json('data.id');
        $this->actingAs($guest)->getJson($base)->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($owner)->getJson("{$base}/{$id}")->assertNotFound();
        $this->actingAs($administrator)->patchJson("{$base}/{$id}", ['name' => 'Changed'])->assertNotFound();
        $this->actingAs($owner)->deleteJson("{$base}/{$id}")->assertNotFound();
        $this->actingAs($guest)->patchJson("{$base}/{$id}", ['name' => 'Favourite finds',
            'description' => 'Private notes'])->assertOk()->assertJsonPath('data.name', 'Favourite finds')
            ->assertJsonPath('data.description', 'Private notes');
        $this->actingAs($guest)->deleteJson("{$base}/{$id}")->assertNoContent();
        $this->actingAs($guest)->getJson("{$base}/{$id}")->assertNotFound();
    }

    public function test_collection_rechecks_photo_visibility_and_reorders_only_visible_rows(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'collection-visibility']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $first = $this->photo($family, $owner);
        $second = $this->photo($family, $owner);
        $third = $this->photo($family, $owner);
        $private = $this->photo($family, $owner, PhotoVisibility::Private);
        $base = "/api/families/{$family->slug}/collections";
        $id = $this->actingAs($member)->postJson($base, ['name' => 'Family favourites'])
            ->assertCreated()->json('data.id');
        foreach ([$first, $second, $third] as $photo) {
            $this->actingAs($member)->postJson("{$base}/{$id}/photos", ['photo_id' => $photo->id])->assertCreated();
        }
        $this->actingAs($member)->postJson("{$base}/{$id}/photos", ['photo_id' => $first->id])
            ->assertCreated()->assertJsonCount(3, 'data.photos');
        $this->actingAs($member)->postJson("{$base}/{$id}/photos", ['photo_id' => $private->id])
            ->assertForbidden();
        $second->update(['visibility' => PhotoVisibility::Private]);
        $this->actingAs($member)->getJson("{$base}/{$id}")->assertOk()
            ->assertJsonCount(2, 'data.photos')->assertJsonPath('data.photos.0.id', $first->id)
            ->assertJsonPath('data.photos.1.id', $third->id);
        $this->actingAs($member)->putJson("{$base}/{$id}/order", [
            'photo_ids' => [$third->id, $first->id],
        ])->assertOk()->assertJsonPath('data.photos.0.id', $third->id)
            ->assertJsonPath('data.photos.1.id', $first->id);
        $this->assertDatabaseHas('collection_photos', [
            'collection_id' => $id, 'photo_id' => $third->id, 'position' => 1,
        ]);
        $this->assertDatabaseHas('collection_photos', [
            'collection_id' => $id, 'photo_id' => $first->id, 'position' => 3,
        ]);
        $this->actingAs($member)->putJson("{$base}/{$id}/order", [
            'photo_ids' => [$first->id],
        ])->assertUnprocessable();
        $outside = $this->photo($family, $owner);
        $this->actingAs($member)->putJson("{$base}/{$id}/order", [
            'photo_ids' => [$third->id, $outside->id],
        ])->assertUnprocessable();
        $this->actingAs($member)->putJson("{$base}/{$id}/order", [
            'photo_ids' => [$third->id, $third->id],
        ])->assertUnprocessable();
        $this->actingAs($owner)->putJson("{$base}/{$id}/order", [
            'photo_ids' => [$third->id, $first->id],
        ])->assertNotFound();
        $second->update(['visibility' => PhotoVisibility::FamilySpace]);
        $this->actingAs($member)->getJson("{$base}/{$id}")->assertOk()->assertJsonCount(3, 'data.photos');
        $this->actingAs($member)->deleteJson("{$base}/{$id}/photos/{$first->id}")->assertNoContent();
        $this->assertDatabaseHas('photos', ['id' => $first->id]);
        $this->assertDatabaseMissing('collection_photos', ['collection_id' => $id, 'photo_id' => $first->id]);
    }

    public function test_event_and_album_bulk_add_only_currently_visible_photos_without_duplicates(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'collection-bulk']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$guest, $guestMembership] = $this->member($family, FamilySpaceRole::Guest);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id,
            'created_by' => $owner->id, 'name' => 'Gathering']);
        $admission = EventAdmission::query()->create(['family_space_id' => $family->id,
            'event_id' => $event->id, 'family_space_membership_id' => $guestMembership->id,
            'admitted_at' => now()]);
        $visibleAlbum = $this->album($family, $owner, $event, GuestParticipation::View);
        $hiddenAlbum = $this->album($family, $owner, $event, GuestParticipation::None);
        $visible = $this->photo($family, $owner);
        $hidden = $this->photo($family, $owner);
        $primaryOnly = $this->photo($family, $owner);
        $primaryOnly->update(['primary_event_id' => $event->id]);
        $this->attach($family, $visibleAlbum, $visible, $owner, 1);
        $this->attach($family, $hiddenAlbum, $hidden, $owner, 1);
        $base = "/api/families/{$family->slug}/collections";
        $id = $this->actingAs($guest)->postJson($base, ['name' => 'Seen at event'])
            ->assertCreated()->json('data.id');
        $this->actingAs($guest)->postJson("{$base}/{$id}/populate", [
            'source_type' => 'event', 'source_id' => $event->id,
        ])->assertOk()->assertJsonPath('added', 1)->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.id', $visible->id);
        $this->actingAs($guest)->postJson("{$base}/{$id}/populate", [
            'source_type' => 'album', 'source_id' => $visibleAlbum->id,
        ])->assertOk()->assertJsonPath('added', 0);
        $this->actingAs($guest)->postJson("{$base}/{$id}/populate", [
            'source_type' => 'album', 'source_id' => $hiddenAlbum->id,
        ])->assertForbidden();
        $admission->update(['revoked_at' => now()]);
        $this->actingAs($guest)->getJson("{$base}/{$id}")->assertOk()->assertJsonCount(0, 'data.photos');
        $this->actingAs($owner)->getJson("{$base}/{$id}")->assertNotFound();
    }

    public function test_batch_add_is_atomic_deduplicated_and_preserves_order(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'collection-batch']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $first = $this->photo($family, $owner);
        $second = $this->photo($family, $owner);
        $third = $this->photo($family, $owner);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Ada']);
        $second->update(['historical_date_precision' => DatePrecision::Year,
            'historical_date' => '1984-01-01', 'location_description' => 'Blackpool']);
        PhotoPerson::query()->create(['family_space_id' => $family->id, 'photo_id' => $second->id,
            'person_id' => $person->id, 'proposal_source' => 'manual',
            'status' => PersonProposalStatus::Approved, 'proposed_by' => $member->id,
            'resolved_by' => $member->id, 'resolved_at' => now()]);
        $base = "/api/families/{$family->slug}/collections";
        $id = $this->actingAs($member)->postJson($base, ['name' => 'Selected'])
            ->assertCreated()->json('data.id');
        $this->actingAs($member)->postJson("{$base}/{$id}/photos", ['photo_id' => $first->id])
            ->assertCreated();

        $this->actingAs($member)->postJson("{$base}/{$id}/photos/batch", [
            'photo_ids' => [$first->id, $second->id, $second->id, $third->id],
        ])->assertCreated()->assertJsonPath('added', 2)->assertJsonCount(3, 'data.photos')
            ->assertJsonPath('data.photos.0.id', $first->id)
            ->assertJsonPath('data.photos.1.id', $second->id)
            ->assertJsonPath('data.photos.1.historical_date.value', '1984')
            ->assertJsonPath('data.photos.1.location_description', 'Blackpool')
            ->assertJsonPath('data.photos.1.people.0.preferred_name', 'Ada')
            ->assertJsonPath('data.photos.2.id', $third->id);
        $this->assertDatabaseHas('collection_photos', [
            'collection_id' => $id, 'photo_id' => $first->id, 'position' => 1,
        ]);
        $this->assertDatabaseHas('collection_photos', [
            'collection_id' => $id, 'photo_id' => $second->id, 'position' => 2,
        ]);
        $this->assertDatabaseHas('collection_photos', [
            'collection_id' => $id, 'photo_id' => $third->id, 'position' => 3,
        ]);
    }

    public function test_batch_add_rejects_any_unauthorized_or_cross_family_photo_without_partial_success(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'collection-batch-atomic']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $visible = $this->photo($family, $owner);
        $private = $this->photo($family, $owner, PhotoVisibility::Private);
        $base = "/api/families/{$family->slug}/collections";
        $id = $this->actingAs($member)->postJson($base, ['name' => 'Atomic'])
            ->assertCreated()->json('data.id');

        $this->actingAs($member)->postJson("{$base}/{$id}/photos/batch", [
            'photo_ids' => [$visible->id, $private->id],
        ])->assertForbidden();
        $this->assertDatabaseMissing('collection_photos', ['collection_id' => $id]);

        $otherFamily = FamilySpace::factory()->create(['slug' => 'collection-batch-other']);
        [$otherOwner] = $this->member($otherFamily, FamilySpaceRole::Owner);
        $outside = $this->photo($otherFamily, $otherOwner);
        $this->actingAs($member)->postJson("{$base}/{$id}/photos/batch", [
            'photo_ids' => [$visible->id, $outside->id],
        ])->assertNotFound();
        $this->assertDatabaseMissing('collection_photos', ['collection_id' => $id]);

        $this->actingAs($owner)->postJson("{$base}/{$id}/photos/batch", [
            'photo_ids' => [$visible->id],
        ])->assertNotFound();
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role]);

        return [$user, $membership];
    }

    private function photo(FamilySpace $family, User $creator, PhotoVisibility $visibility = PhotoVisibility::FamilySpace): Photo
    {
        return Photo::factory()->create(['family_space_id' => $family->id,
            'created_by' => $creator->id, 'visibility' => $visibility]);
    }

    private function album(FamilySpace $family, User $creator, FamilyEvent $event, GuestParticipation $participation): Album
    {
        return Album::query()->create(['family_space_id' => $family->id,
            'created_by' => $creator->id, 'name' => 'Event Album', 'event_id' => $event->id,
            'visibility' => AlbumVisibility::FamilySpace, 'guest_participation' => $participation]);
    }

    private function attach(FamilySpace $family, Album $album, Photo $photo, User $actor, int $position): void
    {
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => $position, 'added_by' => $actor->id]);
    }
}
