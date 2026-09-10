<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Enums\MembershipState;
use App\Enums\NotificationCategory;
use App\Jobs\FinalizeContributionNotification;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\ContributionGroup;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\NotificationCandidate;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\User;
use App\Services\NotificationManager;
use App\Tenancy\TenantOperationContext;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class NotificationHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_preferences_have_safe_defaults_and_can_be_changed(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'notification-family']);
        $user = User::factory()->create();
        FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $user->id, 'role' => FamilySpaceRole::Member, 'state' => MembershipState::Active, 'accepted_at' => now()]);

        $this->actingAs($user)->getJson('/api/families/notification-family/notification-preferences')
            ->assertOk()->assertJsonFragment(['category' => 'comment', 'channel' => 'email', 'enabled' => true])
            ->assertJsonFragment(['category' => 'contribution', 'channel' => 'email', 'enabled' => false]);

        $this->actingAs($user)->putJson('/api/families/notification-family/notification-preferences', ['preferences' => [['category' => 'contribution', 'channel' => 'email', 'enabled' => true]]])
            ->assertOk()->assertJsonFragment(['category' => 'contribution', 'channel' => 'email', 'enabled' => true]);
    }

    public function test_only_the_recipient_can_read_a_notification(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'private-notifications']);
        $recipient = User::factory()->create();
        $other = User::factory()->create();
        foreach ([$recipient, $other] as $user) {
            FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $user->id, 'role' => FamilySpaceRole::Member, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        }
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $recipient->id, 'name' => 'Private album', 'visibility' => AlbumVisibility::FamilySpace]);
        $notification = FamilyNotification::query()->create(['family_space_id' => $family->id, 'recipient_user_id' => $recipient->id, 'category' => 'contribution', 'source_action_id' => (string) Str::ulid(), 'album_id' => $album->id]);
        $this->actingAs($other)->patchJson("/api/families/private-notifications/notifications/{$notification->id}/read")->assertNotFound();
    }

    public function test_candidate_processing_is_durable_and_channel_independent(): void
    {
        Notification::fake();
        [$family, $owner, $actor, $album, $photo] = $this->scenario('candidate-family');
        $comment = PhotoComment::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id, 'album_id' => $album->id, 'author_id' => $actor->id, 'body' => 'A family comment.']);
        $sourceId = $comment->id;
        $subject = ['photo_id' => $photo->id, 'album_id' => $album->id, 'comment_id' => $comment->id];
        $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();

        $manager = app(NotificationManager::class);
        $manager->process($context, NotificationCategory::Comment, $sourceId, $subject);
        $manager->process($context, NotificationCategory::Comment, $sourceId, $subject);

        $this->assertDatabaseCount('notification_candidates', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('notification_deliveries', 1);
        $this->assertNotNull(NotificationCandidate::query()->firstOrFail()->evaluated_at);
        $this->assertSame('sent', NotificationDelivery::query()->firstOrFail()->status);
        Notification::assertCount(1);
    }

    public function test_email_can_deliver_without_in_app_and_reenabling_does_not_revisit_the_candidate(): void
    {
        Notification::fake();
        [$family, $owner, $actor, $album, $photo] = $this->scenario('preference-independence');
        NotificationPreference::query()->create(['family_space_id' => $family->id, 'user_id' => $owner->id, 'category' => 'comment', 'channel' => 'in_app', 'enabled' => false]);
        NotificationPreference::query()->create(['family_space_id' => $family->id, 'user_id' => $owner->id, 'category' => 'comment', 'channel' => 'email', 'enabled' => true]);
        $comment = PhotoComment::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id, 'album_id' => $album->id, 'author_id' => $actor->id, 'body' => 'A family comment.']);
        $subject = ['photo_id' => $photo->id, 'album_id' => $album->id, 'comment_id' => $comment->id];
        $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();

        $manager = app(NotificationManager::class);
        $manager->process($context, NotificationCategory::Comment, $comment->id, $subject);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseHas('notification_candidates', ['recipient_user_id' => $owner->id, 'source_action_id' => $comment->id, 'in_app_outcome' => 'skipped_preference', 'email_outcome' => 'created']);
        $this->assertDatabaseHas('notification_deliveries', ['recipient_user_id' => $owner->id, 'source_action_id' => $comment->id, 'status' => 'sent']);
        NotificationPreference::query()->where(['family_space_id' => $family->id, 'user_id' => $owner->id, 'category' => 'comment', 'channel' => 'in_app'])->update(['enabled' => true]);

        $manager->process($context, NotificationCategory::Comment, $comment->id, $subject);

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('notification_deliveries', 1);
        Notification::assertCount(1);
    }

    public function test_a_notification_disappears_when_its_subject_access_is_revoked(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'notification-revocation']);
        $owner = User::factory()->create();
        $contributor = User::factory()->create();
        FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $owner->id, 'role' => FamilySpaceRole::Owner, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        $membership = FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $contributor->id, 'role' => FamilySpaceRole::Contributor, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Selected album', 'visibility' => AlbumVisibility::Selected]);
        $grant = AlbumGrant::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id, 'family_space_membership_id' => $membership->id, 'can_view' => true, 'can_contribute' => false, 'granted_by' => $owner->id]);
        FamilyNotification::query()->create(['family_space_id' => $family->id, 'recipient_user_id' => $contributor->id, 'category' => 'contribution', 'source_action_id' => (string) Str::ulid(), 'album_id' => $album->id]);

        $this->actingAs($contributor)->getJson('/api/families/notification-revocation/notifications')
            ->assertOk()->assertJsonCount(1, 'data');

        $grant->delete();

        $this->actingAs($contributor)->getJson('/api/families/notification-revocation/notifications')
            ->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_email_retry_does_not_resend_a_recipient_already_delivered_successfully(): void
    {
        [$family, $owner, $actor, $album, $photo] = $this->scenario('notification-retry');
        $participant = User::factory()->create();
        FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $participant->id, 'role' => FamilySpaceRole::Member, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        PhotoComment::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id, 'album_id' => $album->id, 'author_id' => $participant->id, 'body' => 'Earlier comment.']);
        $comment = PhotoComment::query()->create(['family_space_id' => $family->id, 'photo_id' => $photo->id, 'album_id' => $album->id, 'author_id' => $actor->id, 'body' => 'New comment.']);
        $dispatcher = new RetryingNotificationDispatcher($participant->id);
        $this->app->instance(Dispatcher::class, $dispatcher);
        $manager = app(NotificationManager::class);
        $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();
        $subject = ['photo_id' => $photo->id, 'album_id' => $album->id, 'comment_id' => $comment->id];

        try {
            $manager->process($context, NotificationCategory::Comment, $comment->id, $subject);
            $this->fail('The first delivery pass unexpectedly completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification failure.', $exception->getMessage());
        }

        $manager->process($context, NotificationCategory::Comment, $comment->id, $subject);

        $this->assertSame(1, $dispatcher->successfulDeliveries[$owner->id] ?? 0);
        $this->assertSame(1, $dispatcher->successfulDeliveries[$participant->id] ?? 0);
        $this->assertSame(2, $dispatcher->attempts[$participant->id] ?? 0);
        $this->assertDatabaseCount('notification_candidates', 2);
        $this->assertDatabaseCount('notification_deliveries', 2);
        $this->assertSame(2, NotificationDelivery::query()->where('status', 'sent')->count());
    }

    public function test_grouped_contributions_converge_and_late_arrivals_stand_alone(): void
    {
        Notification::fake();
        Queue::fake([FinalizeContributionNotification::class]);
        [$family, $owner, $actor, $album, $firstPhoto] = $this->scenario('convergence-family', '01M30000000000000000000001');
        $secondPhoto = $this->photo($family, $actor, '01M30000000000000000000001');
        $firstLink = $this->link($family, $album, $firstPhoto, $actor, 1);
        $secondLink = $this->link($family, $album, $secondPhoto, $actor, 2);
        $group = ContributionGroup::query()->create(['family_space_id' => $family->id, 'actor_user_id' => $actor->id, 'upload_batch_id' => '01M30000000000000000000001', 'album_id' => $album->id]);
        $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();
        $manager = app(NotificationManager::class);

        foreach ([[$firstPhoto, $firstLink], [$secondPhoto, $secondLink]] as [$photo, $link]) {
            $manager->registerContribution($context, $group->id, ['photo_id' => $photo->id, 'album_id' => $album->id, 'album_photo_id' => $link->id, 'contribution_group_id' => $group->id]);
        }
        $manager->finalizeContribution($context, $group->id);
        $this->assertDatabaseCount('notifications', 1);

        $latePhoto = $this->photo($family, $actor, '01M30000000000000000000001');
        $lateLink = $this->link($family, $album, $latePhoto, $actor, 3);
        $manager->registerContribution($context, $group->id, ['photo_id' => $latePhoto->id, 'album_id' => $album->id, 'album_photo_id' => $lateLink->id, 'contribution_group_id' => $group->id]);

        $this->assertDatabaseCount('notifications', 2);
        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'source_action_id' => $lateLink->id]);
    }

    public function test_the_same_batch_isolated_by_album_and_actor_creates_distinct_groups(): void
    {
        Notification::fake();
        Queue::fake([FinalizeContributionNotification::class]);
        [$family, $owner, $firstActor, $firstAlbum, $firstPhoto] = $this->scenario('group-identity', '01M30000000000000000000002');
        $secondActor = User::factory()->create();
        FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $secondActor->id, 'role' => FamilySpaceRole::Member, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        $secondAlbum = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Second album', 'visibility' => AlbumVisibility::FamilySpace]);
        $sameActorOtherAlbumPhoto = $this->photo($family, $firstActor, '01M30000000000000000000002');
        $otherActorSameAlbumPhoto = $this->photo($family, $secondActor, '01M30000000000000000000002');
        $cases = [
            [$firstActor, $firstAlbum, $firstPhoto, $this->link($family, $firstAlbum, $firstPhoto, $firstActor, 1)],
            [$firstActor, $secondAlbum, $sameActorOtherAlbumPhoto, $this->link($family, $secondAlbum, $sameActorOtherAlbumPhoto, $firstActor, 1)],
            [$secondActor, $firstAlbum, $otherActorSameAlbumPhoto, $this->link($family, $firstAlbum, $otherActorSameAlbumPhoto, $secondActor, 2)],
        ];
        $manager = app(NotificationManager::class);
        $groupIds = [];
        foreach ($cases as [$actor, $album, $photo, $link]) {
            $group = ContributionGroup::query()->create(['family_space_id' => $family->id, 'actor_user_id' => $actor->id, 'upload_batch_id' => '01M30000000000000000000002', 'album_id' => $album->id]);
            $groupIds[] = $group->id;
            $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();
            $manager->registerContribution($context, $group->id, ['photo_id' => $photo->id, 'album_id' => $album->id, 'album_photo_id' => $link->id, 'contribution_group_id' => $group->id]);
            $manager->finalizeContribution($context, $group->id);
        }

        $this->assertDatabaseCount('contribution_groups', 3);
        $this->assertDatabaseCount('notification_candidates', 3);
        $this->assertDatabaseCount('notifications', 3);
        foreach ($groupIds as $groupId) {
            $this->assertDatabaseHas('notifications', ['recipient_user_id' => $owner->id, 'source_action_id' => $groupId]);
        }
    }

    public function test_revoked_event_admission_between_candidate_and_delivery_prevents_disclosure(): void
    {
        Notification::fake();
        Queue::fake([FinalizeContributionNotification::class]);
        [$family, $owner, $actor, $album, $photo] = $this->scenario('admission-recheck', '01M30000000000000000000003');
        $guest = User::factory()->create();
        $guestMembership = FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $guest->id, 'role' => FamilySpaceRole::Guest, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        $event = FamilyEvent::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Family celebration']);
        $album->update(['event_id' => $event->id, 'guest_participation' => GuestParticipation::View]);
        $admission = EventAdmission::query()->create(['family_space_id' => $family->id, 'event_id' => $event->id, 'family_space_membership_id' => $guestMembership->id, 'admitted_at' => now()]);
        $link = $this->link($family, $album, $photo, $actor, 1);
        $group = ContributionGroup::query()->create(['family_space_id' => $family->id, 'actor_user_id' => $actor->id, 'upload_batch_id' => '01M30000000000000000000003', 'album_id' => $album->id]);
        $context = TenantOperationContext::forBackground($family->id, $actor->id)->toArray();
        $manager = app(NotificationManager::class);

        $manager->registerContribution($context, $group->id, ['photo_id' => $photo->id, 'album_id' => $album->id, 'album_photo_id' => $link->id, 'contribution_group_id' => $group->id]);
        $this->assertDatabaseHas('notification_candidates', ['recipient_user_id' => $guest->id, 'source_action_id' => $group->id, 'in_app_outcome' => 'pending']);
        $admission->update(['revoked_at' => now(), 'revoked_by' => $owner->id]);

        $manager->finalizeContribution($context, $group->id);

        $this->assertDatabaseHas('notification_candidates', ['recipient_user_id' => $guest->id, 'source_action_id' => $group->id, 'in_app_outcome' => 'skipped_authorization', 'email_outcome' => 'skipped_authorization']);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $guest->id, 'source_action_id' => $group->id]);
    }

    /** @return array{FamilySpace, User, User, Album, Photo} */
    private function scenario(string $slug, ?string $batchId = null): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = User::factory()->create();
        $actor = User::factory()->create();
        foreach ([[$owner, FamilySpaceRole::Owner], [$actor, FamilySpaceRole::Member]] as [$user, $role]) {
            FamilySpaceMembership::query()->create(['family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role, 'state' => MembershipState::Active, 'accepted_at' => now()]);
        }
        $album = Album::query()->create(['family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Family album', 'visibility' => AlbumVisibility::FamilySpace]);

        return [$family, $owner, $actor, $album, $this->photo($family, $actor, $batchId)];
    }

    private function photo(FamilySpace $family, User $actor, ?string $batchId): Photo
    {
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $actor->id, 'upload_batch_id' => $batchId]);

        return Photo::factory()->create(['family_space_id' => $family->id, 'media_upload_id' => $upload->id, 'created_by' => $actor->id]);
    }

    private function link(FamilySpace $family, Album $album, Photo $photo, User $actor, int $position): AlbumPhoto
    {
        return AlbumPhoto::query()->create(['family_space_id' => $family->id, 'album_id' => $album->id, 'photo_id' => $photo->id, 'added_by' => $actor->id, 'position' => $position]);
    }
}

class RetryingNotificationDispatcher implements Dispatcher
{
    /** @var array<int, int> */
    public array $attempts = [];

    /** @var array<int, int> */
    public array $successfulDeliveries = [];

    private bool $failed = false;

    public function __construct(private readonly int $failUserId) {}

    public function send($notifiables, $notification): void
    {
        /** @var User $recipient */
        $recipient = $notifiables;
        $this->attempts[$recipient->id] = ($this->attempts[$recipient->id] ?? 0) + 1;
        if ($recipient->id === $this->failUserId && ! $this->failed) {
            $this->failed = true;
            throw new RuntimeException('Simulated notification failure.');
        }

        $this->successfulDeliveries[$recipient->id] = ($this->successfulDeliveries[$recipient->id] ?? 0) + 1;
    }

    /** @param list<string>|null $channels */
    public function sendNow($notifiables, $notification, ?array $channels = null): void
    {
        $this->send($notifiables, $notification);
    }
}
