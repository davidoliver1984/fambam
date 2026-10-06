<?php

namespace Tests\Feature;

use App\Enums\DatePrecision;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Jobs\DeliverPhotoMemoryNotification;
use App\Models\FamilyNotification;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\NotificationCandidate;
use App\Models\NotificationDelivery;
use App\Models\NotificationPreference;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\FamilyActivityNotification;
use App\Queries\DateMemoryQuery;
use App\Services\NotificationManager;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PhotoMemoryNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_real_memory_creates_one_in_app_notification_and_scheduler_reruns_do_not_duplicate_it(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [$family, $viewer] = $this->familyMember('memory-delivery');
        $photo = $this->memory($family, $viewer, '1984-10-05');

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();
        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseHas('notifications', [
            'family_space_id' => $family->id,
            'recipient_user_id' => $viewer->id,
            'category' => 'photo_memory',
            'photo_id' => $photo->id,
        ]);
        $this->assertSame(1, FamilyNotification::query()->where('recipient_user_id', $viewer->id)->count());
        $this->assertSame(1, NotificationCandidate::query()->where('recipient_user_id', $viewer->id)->count());
        $this->assertSame(0, NotificationDelivery::query()->where('recipient_user_id', $viewer->id)->count());
        Notification::assertNothingSent();
    }

    public function test_scheduler_and_worker_deliver_once_through_the_database_queue(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [$family, $viewer] = $this->familyMember('queued-memory-delivery');
        $photo = $this->memory($family, $viewer, '1984-10-05');

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();
        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $viewer->id]);

        $this->artisan('queue:work', [
            'connection' => 'database',
            '--once' => true,
            '--sleep' => 0,
        ])->assertSuccessful();

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('notifications', [
            'family_space_id' => $family->id,
            'recipient_user_id' => $viewer->id,
            'category' => 'photo_memory',
            'photo_id' => $photo->id,
        ]);
        $this->assertSame(1, FamilyNotification::query()->where('recipient_user_id', $viewer->id)->count());
    }

    public function test_no_memory_creates_no_notification_candidate_or_email(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [, $viewer] = $this->familyMember('no-memory');

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseMissing('notification_candidates', ['recipient_user_id' => $viewer->id]);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $viewer->id]);
        $this->assertDatabaseMissing('notification_deliveries', ['recipient_user_id' => $viewer->id]);
        Notification::assertNothingSent();
    }

    public function test_channel_preferences_are_independent_and_email_uses_the_existing_authenticated_photo_destination(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [$family, $viewer] = $this->familyMember('memory-email');
        $photo = $this->memory($family, $viewer, '1984-10-05');
        $this->preference($family, $viewer, 'in_app', false);
        $this->preference($family, $viewer, 'email', true);

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $viewer->id]);
        $this->assertDatabaseHas('notification_deliveries', [
            'recipient_user_id' => $viewer->id,
            'category' => 'photo_memory',
            'photo_id' => $photo->id,
            'channel' => 'mail',
            'status' => 'sent',
        ]);
        Notification::assertSentTo($viewer, FamilyActivityNotification::class, function (FamilyActivityNotification $notification) use ($family, $photo, $viewer): bool {
            $mail = $notification->toMail($viewer);

            return $mail->introLines === ['A photo memory from this day is waiting for you.']
                && str_ends_with((string) $mail->actionUrl, "/families/{$family->slug}/photos/{$photo->id}")
                && ! str_contains((string) $mail->actionUrl, 'signature=');
        });
    }

    public function test_both_channels_enabled_create_in_app_and_email_delivery(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [$family, $viewer] = $this->familyMember('memory-both');
        $photo = $this->memory($family, $viewer, '1984-10-05');
        $this->preference($family, $viewer, 'in_app', true);
        $this->preference($family, $viewer, 'email', true);

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $viewer->id, 'photo_id' => $photo->id]);
        $this->assertDatabaseHas('notification_deliveries', ['recipient_user_id' => $viewer->id, 'photo_id' => $photo->id, 'status' => 'sent']);
        Notification::assertSentTo($viewer, FamilyActivityNotification::class);
    }

    public function test_account_timezone_determines_due_local_date(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-01-02 00:15:00 UTC');
        [$tokyoFamily, $tokyoViewer] = $this->familyMember('tokyo-memory', 'Asia/Tokyo');
        $tokyoPhoto = $this->memory($tokyoFamily, $tokyoViewer, '1984-01-02');
        [$utcFamily, $utcViewer] = $this->familyMember('utc-memory', 'UTC');
        $this->memory($utcFamily, $utcViewer, '1984-01-02');

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseHas('notifications', ['recipient_user_id' => $tokyoViewer->id, 'photo_id' => $tokyoPhoto->id]);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $utcViewer->id]);
    }

    public function test_a_queued_evaluation_is_discarded_after_the_users_local_date_changes(): void
    {
        Notification::fake();
        [$family, $viewer] = $this->familyMember('stale-memory-job');
        $this->memory($family, $viewer, '1984-10-05');
        $job = new DeliverPhotoMemoryNotification(
            TenantOperationContext::forBackground($family->id, $viewer->id)->toArray(),
            '2026-10-05',
        );
        CarbonImmutable::setTestNow('2026-10-06 00:01:00 UTC');

        $job->handle(
            app(DatabaseTenantContext::class),
            app(TenantContext::class),
            app(DateMemoryQuery::class),
            app(NotificationManager::class),
        );

        $this->assertDatabaseMissing('notification_candidates', ['recipient_user_id' => $viewer->id]);
        $this->assertDatabaseMissing('notifications', ['recipient_user_id' => $viewer->id]);
        Notification::assertNothingSent();
    }

    public function test_the_same_user_receives_one_independently_scoped_memory_per_family_space(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        $viewer = User::factory()->create(['timezone' => 'UTC']);
        $families = [
            FamilySpace::factory()->create(['slug' => 'first-scoped-memory']),
            FamilySpace::factory()->create(['slug' => 'second-scoped-memory']),
        ];
        foreach ($families as $index => $family) {
            FamilySpaceMembership::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $viewer->id,
                'role' => FamilySpaceRole::Member,
                'state' => MembershipState::Active,
            ]);
            $this->memory($family, $viewer, ($index === 0 ? '1984' : '1985').'-10-05');
        }

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();
        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertSame(2, FamilyNotification::query()->where('recipient_user_id', $viewer->id)->count());
        foreach ($families as $family) {
            $this->assertSame(1, FamilyNotification::query()
                ->where('family_space_id', $family->id)
                ->where('recipient_user_id', $viewer->id)
                ->where('category', 'photo_memory')
                ->count());
        }
    }

    public function test_inaccessible_and_cross_family_photos_are_not_disclosed(): void
    {
        Notification::fake();
        CarbonImmutable::setTestNow('2026-10-05 09:15:00 UTC');
        [$family, $viewer] = $this->familyMember('private-memory');
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $this->memory($family, $owner, '1984-10-05', PhotoVisibility::Private);
        [$otherFamily, $otherOwner] = $this->familyMember('other-memory');
        $this->memory($otherFamily, $otherOwner, '1985-10-05');

        $this->artisan('fambam:dispatch-photo-memory-notifications')->assertSuccessful();

        $this->assertDatabaseMissing('notification_candidates', ['family_space_id' => $family->id, 'recipient_user_id' => $viewer->id]);
        $this->assertDatabaseMissing('notifications', ['family_space_id' => $family->id, 'recipient_user_id' => $viewer->id]);
    }

    /** @return array{FamilySpace, User} */
    private function familyMember(string $slug, string $timezone = 'UTC'): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $viewer = User::factory()->create(['timezone' => $timezone]);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $viewer->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);

        return [$family, $viewer];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create(['timezone' => 'UTC']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'state' => MembershipState::Active,
        ]);

        return $user;
    }

    private function memory(
        FamilySpace $family,
        User $creator,
        string $date,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
    ): Photo {
        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
            'historical_date' => $date,
            'historical_date_precision' => DatePrecision::Exact,
            'do_not_resurface' => false,
        ]);
    }

    private function preference(FamilySpace $family, User $user, string $channel, bool $enabled): void
    {
        NotificationPreference::query()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'category' => 'photo_memory',
            'channel' => $channel,
            'enabled' => $enabled,
        ]);
    }
}
