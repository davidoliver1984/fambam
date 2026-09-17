<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\LoveNotificationGroup;
use App\Models\LoveNotificationGroupActor;
use App\Models\NotificationCandidate;
use App\Models\NotificationPreference;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\Reaction;
use App\Models\Story;
use App\Models\User;
use App\Notifications\FamilyActivityNotification;
use App\Services\NotificationManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoveHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_album_event_and_story_love_is_idempotent_and_view_authorized(): void
    {
        Queue::fake();
        [$family, $owner, $member] = $this->family('love-targets');
        $outsider = $this->member($family, FamilySpaceRole::Guest);
        $album = $this->album($family, $owner);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id,
            'created_by' => $owner->id, 'name' => 'Family gathering']);
        $story = Story::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'author_id' => $owner->id, 'body' => $this->document('A memory'), 'body_plain_text' => 'A memory']);
        $base = "/api/families/{$family->slug}";
        foreach (["albums/{$album->id}", "events/{$event->id}", "stories/{$story->id}"] as $path) {
            $this->actingAs($member)->putJson("{$base}/{$path}/love")->assertOk()
                ->assertJsonPath('data.count', 1)->assertJsonPath('data.loved_by_me', true);
            $this->actingAs($member)->putJson("{$base}/{$path}/love")->assertOk()
                ->assertJsonPath('data.count', 1);
            $this->assertContains($this->actingAs($outsider)->getJson("{$base}/{$path}/love")->status(), [403, 404]);
            $this->actingAs($owner)->deleteJson("{$base}/{$path}/love")->assertNoContent();
            $this->actingAs($owner)->getJson("{$base}/{$path}/love")->assertJsonPath('data.count', 1);
            $this->actingAs($member)->deleteJson("{$base}/{$path}/love")->assertNoContent();
            $this->actingAs($owner)->getJson("{$base}/{$path}/love")->assertJsonPath('data.count', 0);
        }
        $this->assertDatabaseCount('reactions', 0);
        $this->assertDatabaseCount('family_activities', 0);
    }

    public function test_love_notifications_group_distinct_actors_and_skip_removed_ones(): void
    {
        Queue::fake();
        Notification::fake();
        [$family, $owner, $first] = $this->family('love-grouping');
        $second = $this->member($family, FamilySpaceRole::Member);
        $third = $this->member($family, FamilySpaceRole::Member);
        $album = $this->album($family, $owner);
        $path = "/api/families/{$family->slug}/albums/{$album->id}/love";
        NotificationPreference::query()->create(['family_space_id' => $family->id,
            'user_id' => $owner->id, 'category' => 'love', 'channel' => 'email', 'enabled' => true]);
        $this->actingAs($owner)->putJson($path)->assertOk();
        $this->assertDatabaseCount('love_notification_groups', 0);
        $this->actingAs($first)->putJson($path)->assertOk();
        $this->actingAs($second)->putJson($path)->assertOk();
        $this->actingAs($second)->putJson($path)->assertOk();
        $this->assertDatabaseCount('love_notification_groups', 1);
        $this->assertDatabaseCount('love_notification_group_actors', 2);
        $group = LoveNotificationGroup::query()->firstOrFail();
        $this->actingAs($second)->deleteJson($path)->assertNoContent();
        $this->assertDatabaseCount('love_notification_group_actors', 1);
        $context = TenantOperationContext::forBackground($family->id, $first->id)->toArray();
        app(NotificationManager::class)->finalizeLove($context, $group->id);
        app(NotificationManager::class)->finalizeLove($context, $group->id);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id,
            'category' => 'love', 'source_action_id' => $group->id, 'album_id' => $album->id]);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        Notification::assertCount(1);
        Notification::assertSentTo($owner, FamilyActivityNotification::class,
            fn (FamilyActivityNotification $notification): bool => in_array(
                "{$first->name} loved your Album.", $notification->toMail($owner)->introLines, true,
            ));
        $this->actingAs($second)->putJson($path)->assertOk();
        $this->actingAs($third)->putJson($path)->assertOk();
        $this->assertDatabaseCount('love_notification_groups', 2);
        $this->assertSame(2, NotificationCandidate::query()->where('category', 'love')->count());
        $nextGroup = LoveNotificationGroup::query()->whereKeyNot($group->id)->firstOrFail();
        app(NotificationManager::class)->finalizeLove($context, $nextGroup->id);
        $this->assertDatabaseCount('notifications', 2);
        Notification::assertCount(2);
        Notification::assertSentTo($owner, FamilyActivityNotification::class,
            fn (FamilyActivityNotification $notification): bool => in_array(
                "{$second->name} and 1 others loved your Album.", $notification->toMail($owner)->introLines, true,
            ));
    }

    public function test_zero_actor_wave_is_terminal_and_photo_love_preserves_album_authority(): void
    {
        Queue::fake();
        [$family, $owner, $member] = $this->family('photo-love');
        $album = $this->album($family, $owner);
        $secondAlbum = $this->album($family, $owner);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $album->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id]);
        $secondAlbum->photos()->attach($photo->id, ['id' => (string) Str::ulid(),
            'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id]);
        $base = "/api/families/{$family->slug}/photos/{$photo->id}";
        $this->actingAs($member)->putJson("{$base}/reaction", ['album_id' => $album->id,
            'reaction' => 'love'])->assertOk();
        $this->actingAs($member)->getJson("{$base}/love?album_id={$album->id}")
            ->assertOk()->assertJsonPath('data.count', 1);
        $this->actingAs($member)->putJson("{$base}/reaction", ['album_id' => $secondAlbum->id,
            'reaction' => 'love'])->assertOk();
        $this->assertDatabaseCount('love_notification_group_actors', 1);
        $group = LoveNotificationGroup::query()->firstOrFail();
        $this->actingAs($member)->deleteJson("{$base}/reaction?album_id={$album->id}")->assertNoContent();
        $this->assertDatabaseCount('love_notification_group_actors', 1);
        $this->actingAs($member)->deleteJson("{$base}/reaction?album_id={$secondAlbum->id}")->assertNoContent();
        $this->assertDatabaseCount('love_notification_group_actors', 0);
        app(NotificationManager::class)->finalizeLove(
            TenantOperationContext::forBackground($family->id, $member->id)->toArray(), $group->id,
        );
        $this->assertDatabaseHas('notification_candidates', ['source_action_id' => $group->id,
            'in_app_outcome' => 'skipped_no_actors', 'email_outcome' => 'skipped_no_actors']);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('reactions', 0);
    }

    public function test_event_love_notification_rechecks_the_organisers_event_access(): void
    {
        Queue::fake();
        [$family, , $member] = $this->family('love-event-revocation');
        $organiser = $this->member($family, FamilySpaceRole::Contributor);
        $membership = FamilySpaceMembership::query()->where('user_id', $organiser->id)->firstOrFail();
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id,
            'created_by' => $organiser->id, 'name' => 'Gathering']);
        $admission = EventAdmission::query()->create(['family_space_id' => $family->id,
            'event_id' => $event->id, 'family_space_membership_id' => $membership->id,
            'admitted_at' => now()]);
        $this->actingAs($member)->putJson("/api/families/{$family->slug}/events/{$event->id}/love")
            ->assertOk();
        $group = LoveNotificationGroup::query()->firstOrFail();
        $admission->update(['revoked_at' => now()]);
        app(NotificationManager::class)->finalizeLove(
            TenantOperationContext::forBackground($family->id, $member->id)->toArray(), $group->id,
        );
        $this->assertDatabaseHas('notification_candidates', ['source_action_id' => $group->id,
            'recipient_user_id' => $organiser->id, 'in_app_outcome' => 'skipped_authorization']);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_linked_person_is_hidden_from_viewer_without_person_authority(): void
    {
        Queue::fake();
        [$family, $owner, $member] = $this->family('love-person-disclosure');
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $membership = FamilySpaceMembership::query()->where('user_id', $contributor->id)->firstOrFail();
        $album = $this->album($family, $owner, AlbumVisibility::Selected);
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id, 'can_view' => true,
            'can_contribute' => false, 'granted_by' => $owner->id]);
        $memberMembership = FamilySpaceMembership::query()->where('user_id', $member->id)->firstOrFail();
        AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $memberMembership->id, 'can_view' => true,
            'can_contribute' => false, 'granted_by' => $owner->id]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        PersonAccountLink::query()->create(['family_space_id' => $family->id,
            'person_id' => $person->id, 'user_id' => $member->id, 'created_by' => $owner->id]);
        $path = "/api/families/{$family->slug}/albums/{$album->id}/love";
        $this->actingAs($member)->putJson($path)->assertOk();
        $this->actingAs($owner)->getJson($path)->assertOk()
            ->assertJsonPath('data.reactors.0.person.id', $person->id);
        $this->actingAs($contributor)->getJson($path)->assertOk()
            ->assertJsonPath('data.reactors.0.name', $member->name)
            ->assertJsonPath('data.reactors.0.person', null);
        $this->assertDatabaseCount('reactions', 1);
        $this->assertSame($member->id, Reaction::query()->firstOrFail()->user_id);
        $this->assertSame(1, LoveNotificationGroupActor::query()->count());
    }

    /** @return array{FamilySpace, User, User} */
    private function family(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $member = $this->member($family, FamilySpaceRole::Member);

        return [$family, $owner, $member];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::query()->create(['family_space_id' => $family->id,
            'user_id' => $user->id, 'role' => $role, 'state' => MembershipState::Active,
            'accepted_at' => now()]);

        return $user;
    }

    private function album(FamilySpace $family, User $owner, AlbumVisibility $visibility = AlbumVisibility::FamilySpace): Album
    {
        return Album::query()->create(['family_space_id' => $family->id,
            'created_by' => $owner->id, 'name' => 'Family memories', 'visibility' => $visibility]);
    }

    /** @return array<string, mixed> */
    private function document(string $text): array
    {
        return ['schema_version' => 1, 'blocks' => [['type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]]]]];
    }
}
