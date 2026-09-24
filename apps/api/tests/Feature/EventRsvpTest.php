<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\NotificationCategory;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventRsvpTest extends TestCase
{
    use RefreshDatabase;

    public function test_rsvp_is_self_service_and_groupings_exclude_revoked_and_expired_admissions(): void
    {
        Notification::fake();
        [$family, $owner, $event] = $this->event();
        [$guest, $guestMembership] = $this->member($family, FamilySpaceRole::Guest);
        [$contributor, $contributorMembership] = $this->member($family, FamilySpaceRole::Contributor);
        $guestAdmission = $this->admit($family, $event, $guestMembership);
        $contributorAdmission = $this->admit($family, $event, $contributorMembership);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        DB::table('event_people')->insert(['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'event_id' => $event->id,
            'person_id' => $person->id, 'added_by' => $owner->id, 'created_at' => now()]);
        Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Private event album', 'event_id' => $event->id,
            'visibility' => AlbumVisibility::Selected]);
        $base = "/api/families/{$family->slug}/events/{$event->id}";

        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'going'])
            ->assertOk()->assertJsonPath('data.rsvp_status', 'going');
        $this->actingAs($contributor)->patchJson("{$base}/rsvp", ['status' => 'not_attending'])
            ->assertOk()->assertJsonPath('data.rsvp_status', 'not_attending');
        $this->actingAs($contributor)->getJson($base)->assertOk()
            ->assertJsonCount(0, 'data.people')->assertJsonCount(0, 'data.attendees')
            ->assertJsonCount(0, 'data.albums')->assertJsonPath('data.permissions.can_create_album', false);
        $this->actingAs($contributor)->getJson("{$base}/rsvps")->assertOk();
        $otherEvent = FamilyEvent::query()->create(['family_space_id' => $family->id,
            'created_by' => $owner->id, 'name' => 'Other gathering']);
        $this->actingAs($contributor)->getJson("/api/families/{$family->slug}/events/{$otherEvent->id}")
            ->assertForbidden();
        $this->actingAs($contributor)->getJson("/api/families/{$family->slug}/events")
            ->assertForbidden();
        $this->actingAs($owner)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertForbidden();
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'maybe'])->assertUnprocessable();

        $this->actingAs($owner)->getJson("{$base}/rsvps")
            ->assertOk()->assertJsonCount(1, 'data.going')->assertJsonCount(1, 'data.not_attending')
            ->assertJsonCount(0, 'data.pending');
        $guestAdmission->update(['revoked_at' => now(), 'revoked_by' => $owner->id]);
        $contributorAdmission->update(['admitted_at' => now()->subDays((int) config('events.admission_lifetime_days'))]);
        $this->actingAs($owner)->getJson("{$base}/rsvps")
            ->assertOk()->assertJsonCount(0, 'data.going')->assertJsonCount(0, 'data.not_attending');
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertForbidden();
        $this->actingAs($contributor)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertForbidden();
        $this->assertSame('going', $guestAdmission->fresh()->rsvp_status);
        $this->assertSame('not_attending', $contributorAdmission->fresh()->rsvp_status);
    }

    public function test_readmission_resets_rsvp_but_idempotent_admit_does_not(): void
    {
        Notification::fake();
        [$family, $owner, $event] = $this->event();
        [$guest, $membership] = $this->member($family, FamilySpaceRole::Guest);
        $admission = $this->admit($family, $event, $membership);
        $base = "/api/families/{$family->slug}/events/{$event->id}";
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertOk();
        $this->assertNotNull($admission->fresh()->rsvp_responded_at);

        $this->actingAs($owner)->postJson("{$base}/admissions", ['membership_id' => $membership->id])
            ->assertCreated()->assertJsonPath('data.rsvp_status', 'going');
        $this->actingAs($owner)->deleteJson("{$base}/admissions/{$membership->id}")->assertOk();
        $this->actingAs($owner)->postJson("{$base}/admissions", ['membership_id' => $membership->id])
            ->assertCreated()->assertJsonPath('data.rsvp_status', 'pending')
            ->assertJsonPath('data.rsvp_responded_at', null);
    }

    public function test_any_active_family_member_with_an_event_admission_can_rsvp(): void
    {
        Notification::fake();
        [$family, $owner, $event] = $this->event();
        $ownerMembership = FamilySpaceMembership::query()
            ->where('family_space_id', $family->id)
            ->where('user_id', $owner->id)
            ->firstOrFail();
        $this->admit($family, $event, $ownerMembership);

        $this->actingAs($owner)
            ->patchJson("/api/families/{$family->slug}/events/{$event->id}/rsvp", ['status' => 'going'])
            ->assertOk()
            ->assertJsonPath('data.rsvp_status', 'going');
    }

    public function test_admission_payload_includes_the_linked_person_page(): void
    {
        Notification::fake();
        [$family, $owner, $event] = $this->event();
        [$guest, $membership] = $this->member($family, FamilySpaceRole::Guest);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $guest->id,
            'created_by' => $owner->id,
        ]);
        $this->admit($family, $event, $membership);

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/events/{$event->id}/admissions")
            ->assertOk()
            ->assertJsonPath('data.0.user.person_id', $person->id);
    }

    public function test_first_response_notifies_only_the_event_organiser_without_activity(): void
    {
        Notification::fake();
        [$family, $owner, $event] = $this->event();
        [$guest, $membership] = $this->member($family, FamilySpaceRole::Guest);
        $this->admit($family, $event, $membership);
        $base = "/api/families/{$family->slug}/events/{$event->id}";
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertOk();
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id,
            'category' => NotificationCategory::Attendance->value, 'event_id' => $event->id]);
        $this->assertDatabaseCount('family_activities', 0);
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'going'])->assertOk();
        $this->actingAs($guest)->patchJson("{$base}/rsvp", ['status' => 'not_attending'])->assertOk();
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/notifications")
            ->assertOk()->assertJsonPath('data.0.event_id', $event->id);
        $event->delete();
        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/notifications")
            ->assertOk()->assertJsonCount(0, 'data');
    }

    /** @return array{FamilySpace, User, FamilyEvent} */
    private function event(): array
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id,
            'created_by' => $owner->id, 'name' => 'Gathering']);

        return [$family, $owner, $event];
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role]);

        return [$user, $membership];
    }

    private function admit(FamilySpace $family, FamilyEvent $event, FamilySpaceMembership $membership): EventAdmission
    {
        return EventAdmission::query()->create(['family_space_id' => $family->id,
            'event_id' => $event->id, 'family_space_membership_id' => $membership->id,
            'admitted_at' => now()]);
    }
}
