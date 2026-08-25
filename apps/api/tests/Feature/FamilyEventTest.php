<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\PersonProposalStatus;
use App\Models\Album;
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

class FamilyEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_authority_follows_role_and_creator_rules(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'events-authority']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        [$otherMember] = $this->member($family, FamilySpaceRole::Member);
        [$contributor] = $this->member($family, FamilySpaceRole::Contributor);
        [$guest] = $this->member($family, FamilySpaceRole::Guest);

        $eventId = $this->actingAs($member)->postJson('/api/families/events-authority/events', [
            'name' => 'Summer picnic', 'starts_on' => '2026-08-25', 'ends_on' => '2026-08-26',
        ])->assertCreated()->json('data.id');

        $this->actingAs($member)->patchJson("/api/families/events-authority/events/{$eventId}", ['status' => 'active'])->assertOk();
        $this->actingAs($otherMember)->patchJson("/api/families/events-authority/events/{$eventId}", ['name' => 'No'])->assertForbidden();
        $this->actingAs($owner)->patchJson("/api/families/events-authority/events/{$eventId}", ['name' => 'Family picnic'])->assertOk();
        $this->actingAs($contributor)->getJson('/api/families/events-authority/events')->assertForbidden();
        $this->actingAs($guest)->postJson('/api/families/events-authority/events', ['name' => 'No'])->assertForbidden();
    }

    public function test_event_references_are_tenant_scoped_and_authorization_inert(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-links']);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $otherFamily = FamilySpace::factory()->create();
        [$other] = $this->member($otherFamily, FamilySpaceRole::Owner);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $member->id, 'name' => 'Local']);
        $foreign = FamilyEvent::query()->create(['family_space_id' => $otherFamily->id, 'created_by' => $other->id, 'name' => 'Foreign']);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);

        $albumId = $this->actingAs($member)->postJson('/api/families/event-links/albums', [
            'name' => 'Event album', 'visibility' => AlbumVisibility::FamilySpace->value, 'event_id' => $event->id,
        ])->assertCreated()->assertJsonPath('data.event_id', $event->id)->json('data.id');
        $this->actingAs($member)->patchJson("/api/families/event-links/photos/{$photo->id}", [
            'primary_event_id' => $event->id,
        ])->assertOk()->assertJsonPath('data.primary_event_id', $event->id);
        $this->actingAs($member)->patchJson("/api/families/event-links/albums/{$albumId}", ['event_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('event_id');
        $this->actingAs($member)->patchJson("/api/families/event-links/photos/{$photo->id}", ['primary_event_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('primary_event_id');
    }

    public function test_attendance_and_person_reverse_lookup_are_derived_from_confirmed_photo_people(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-attendance']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Wedding']);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Wedding album', 'visibility' => AlbumVisibility::FamilySpace, 'event_id' => $event->id]);
        $albumPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $primaryPhoto = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'primary_event_id' => $event->id]);
        $album->photos()->attach($albumPhoto->id, ['id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id]);
        $confirmed = Person::factory()->create(['family_space_id' => $family->id]);
        $pending = Person::factory()->create(['family_space_id' => $family->id]);
        foreach ([$albumPhoto, $primaryPhoto] as $photo) {
            PhotoPerson::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id,
                'person_id' => $confirmed->id, 'status' => PersonProposalStatus::Approved, 'proposed_by' => $owner->id]);
        }
        PhotoPerson::query()->create(['family_space_id' => $family->id, 'photo_id' => $albumPhoto->id,
            'person_id' => $pending->id, 'status' => PersonProposalStatus::Pending, 'proposed_by' => $owner->id]);

        $this->actingAs($owner)->getJson("/api/families/event-attendance/events/{$event->id}")
            ->assertOk()->assertJsonCount(1, 'data.attendees')->assertJsonPath('data.attendees.0.id', $confirmed->id);
        $this->actingAs($owner)->getJson("/api/families/event-attendance/people/{$confirmed->id}/events")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $event->id);
        $this->actingAs($owner)->getJson("/api/families/event-attendance/people/{$pending->id}/events")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_duplicate_candidates_are_advisory_and_deterministic(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-duplicates']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $base = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => ' Summer   Picnic ', 'starts_on' => '2026-08-10', 'location' => 'The Park']);
        $sameName = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'summer picnic']);
        $nearLocation = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Birthday', 'starts_on' => '2026-08-17', 'location' => ' the park ']);
        FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Different', 'starts_on' => '2026-08-18', 'location' => 'The Park']);

        $ids = $this->actingAs($owner)->getJson("/api/families/event-duplicates/events/{$base->id}/duplicate-candidates")
            ->assertOk()->json('data.*.id');
        $this->assertEqualsCanonicalizing([$sameName->id, $nearLocation->id], $ids);
        $this->assertDatabaseCount('events', 4);
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
