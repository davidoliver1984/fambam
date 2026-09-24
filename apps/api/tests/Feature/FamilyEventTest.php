<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\PersonProposalStatus;
use App\Models\Album;
use App\Models\AuditEvent;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class FamilyEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_tags_reuse_the_family_vocabulary_and_are_serialized_mutable_and_audited(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-tags']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$otherMember] = $this->member($family, FamilySpaceRole::Member);
        $otherFamily = FamilySpace::factory()->create();
        $existing = Tag::query()->create(['family_space_id' => $family->id,
            'label' => 'Seaside', 'normalized_label' => 'seaside', 'created_by' => $owner->id]);
        $foreign = Tag::query()->create(['family_space_id' => $otherFamily->id,
            'label' => 'Shared', 'normalized_label' => 'shared', 'created_by' => $owner->id]);
        $base = '/api/families/event-tags/events';

        $response = $this->actingAs($owner)->postJson($base, [
            'name' => 'Blackpool holiday',
            'tags' => [' Seaside ', 'seaside', 'Family   holiday', ' Shared '],
        ])->assertCreated()->assertJsonCount(3, 'data.tags')
            ->assertJsonFragment(['id' => $existing->id, 'label' => 'Seaside'])
            ->assertJsonFragment(['label' => 'Family holiday'])
            ->assertJsonFragment(['label' => 'Shared']);
        $eventId = $response->json('data.id');

        $this->assertDatabaseHas('event_tag', ['event_id' => $eventId, 'tag_id' => $existing->id,
            'family_space_id' => $family->id, 'added_by' => $owner->id]);
        $this->assertDatabaseMissing('event_tag', ['event_id' => $eventId, 'tag_id' => $foreign->id]);
        $this->assertDatabaseHas('tags', ['family_space_id' => $family->id,
            'label' => 'Family holiday', 'normalized_label' => 'family holiday']);
        $this->assertDatabaseHas('tags', ['family_space_id' => $family->id,
            'label' => 'Shared', 'normalized_label' => 'shared']);

        $this->actingAs($otherMember)->patchJson("{$base}/{$eventId}", ['tags' => ['No']])->assertForbidden();
        $this->actingAs($owner)->patchJson("{$base}/{$eventId}", ['tags' => ['Seaside', 'New   tag']])
            ->assertOk()->assertJsonCount(2, 'data.tags')->assertJsonFragment(['label' => 'New tag']);
        $audit = AuditEvent::query()->where('action', 'event.updated')->latest('id')->firstOrFail();
        $this->assertContains('tags', $audit->metadata['changed_fields']);

        $this->actingAs($owner)->patchJson("{$base}/{$eventId}", ['tags' => []])
            ->assertOk()->assertJsonCount(0, 'data.tags');
        $this->assertDatabaseMissing('event_tag', ['event_id' => $eventId]);
    }

    public function test_event_tag_limits_match_album_tag_limits(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-tag-limits']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $base = '/api/families/event-tag-limits/events';

        $this->actingAs($owner)->postJson($base, [
            'name' => 'Too many tags', 'tags' => array_map(fn (int $index): string => "Tag {$index}", range(1, 26)),
        ])->assertUnprocessable()->assertJsonValidationErrors('tags');
        $this->actingAs($owner)->postJson($base, [
            'name' => 'Long tag', 'tags' => [str_repeat('a', 81)],
        ])->assertUnprocessable()->assertJsonValidationErrors('tags.0');
    }

    public function test_event_people_are_descriptive_tenant_scoped_and_managed_by_event_editors(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'event-people']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $otherFamily = FamilySpace::factory()->create();
        $foreign = Person::factory()->create(['family_space_id' => $otherFamily->id]);
        $base = '/api/families/event-people/events';

        $eventId = $this->actingAs($owner)->postJson($base, ['name' => 'Reunion',
            'person_ids' => [$person->id]])->assertCreated()->json('data.id');
        $this->actingAs($owner)->getJson("{$base}/{$eventId}")
            ->assertOk()->assertJsonPath('data.people.0.id', $person->id);
        $this->actingAs($member)->patchJson("{$base}/{$eventId}", ['person_ids' => []])->assertForbidden();
        $this->actingAs($owner)->patchJson("{$base}/{$eventId}", ['person_ids' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('person_ids');
        $this->assertDatabaseHas('event_people', ['event_id' => $eventId, 'person_id' => $person->id]);
        $this->actingAs($owner)->patchJson("{$base}/{$eventId}", ['person_ids' => []])->assertOk();
        $this->assertDatabaseMissing('event_people', ['event_id' => $eventId, 'person_id' => $person->id]);
    }

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
        $this->actingAs($owner)->patchJson("/api/families/events-authority/events/{$eventId}", [
            'starts_on' => '2026-08-27', 'ends_on' => '2026-08-26',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->actingAs($owner)->patchJson("/api/families/events-authority/events/{$eventId}", [
            'starts_on' => '2026-08-27',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
        $this->actingAs($owner)->patchJson("/api/families/events-authority/events/{$eventId}", [
            'ends_on' => '2026-08-24',
        ])->assertUnprocessable()->assertJsonValidationErrors('ends_on');
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

        $expectedPreview = collect([$albumPhoto, $primaryPhoto])->sortBy('id')->first();
        $this->actingAs($owner)->getJson("/api/families/event-attendance/events/{$event->id}")
            ->assertOk()->assertJsonCount(1, 'data.attendees')->assertJsonPath('data.attendees.0.id', $confirmed->id)
            ->assertJsonPath('data.presentation.preview.photo_id', $expectedPreview->id)
            ->assertJsonPath('data.presentation.preview.media_upload_id', $expectedPreview->media_upload_id)
            ->assertJsonPath('data.presentation.photo_count', 2)
            ->assertJsonPath('data.presentation.album_count', 1)
            ->assertJsonPath('data.presentation.story_count', 0)
            ->assertJsonPath('data.presentation.people_count', 1);
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
