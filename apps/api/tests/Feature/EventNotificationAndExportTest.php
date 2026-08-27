<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\EventExportState;
use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Enums\MediaUploadState;
use App\Jobs\GenerateEventExport;
use App\Jobs\SendEventContributionNotifications;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\EventExport;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\EventPhotoContributed;
use App\Services\AlbumContributionFinalizer;
use App\Services\AuditRecorder;
use App\Services\EventExportManager;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class EventNotificationAndExportTest extends TestCase
{
    use RefreshDatabase;

    private EventExportStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-08-25T12:00:00+00:00');
        $this->storage = new EventExportStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(MediaDeliveryUrlSigner::class, new EventExportUrlSigner);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_contribution_notification_uses_the_live_event_cohort_and_excludes_the_uploader(): void
    {
        Notification::fake();
        $family = FamilySpace::factory()->create(['slug' => 'notification-cohort']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$administrator] = $this->membership($family, FamilySpaceRole::Administrator, 'Administrator');
        [$uploader] = $this->membership($family, FamilySpaceRole::Guest, 'Uploader');
        [$admitted, $admittedMembership] = $this->membership($family, FamilySpaceRole::Member, 'Admitted');
        [$expired, $expiredMembership] = $this->membership($family, FamilySpaceRole::Guest, 'Expired');
        [$revokedAccount, $revokedAccountMembership] = $this->membership($family, FamilySpaceRole::Guest, 'Revoked account');
        [$revokedAdmission, $revokedAdmissionMembership] = $this->membership($family, FamilySpaceRole::Guest, 'Revoked admission');
        [$unrelated, $unrelatedMembership] = $this->membership($family, FamilySpaceRole::Guest, 'Unrelated admission');
        $revokedAccount->forceFill(['revoked_at' => now()])->save();
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Wedding',
        ]);
        foreach ([[$admittedMembership, now()], [$expiredMembership, now()->subDays(31)], [$revokedAccountMembership, now()]] as [$membership, $admittedAt]) {
            EventAdmission::query()->create([
                'family_space_id' => $family->id,
                'event_id' => $event->id,
                'family_space_membership_id' => $membership->id,
                'admitted_at' => $admittedAt,
            ]);
        }
        EventAdmission::query()->create([
            'family_space_id' => $family->id, 'event_id' => $event->id,
            'family_space_membership_id' => $revokedAdmissionMembership->id,
            'admitted_at' => now(), 'revoked_at' => now(), 'revoked_by' => $owner->id,
        ]);
        $otherEvent = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Other Event',
        ]);
        EventAdmission::query()->create([
            'family_space_id' => $family->id, 'event_id' => $otherEvent->id,
            'family_space_membership_id' => $unrelatedMembership->id, 'admitted_at' => now(),
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $uploader->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id, 'created_by' => $uploader->id,
            'media_upload_id' => $upload->id,
        ]);
        $context = TenantOperationContext::forBackground($family->id, $uploader->id);

        (new SendEventContributionNotifications($context->toArray(), $event->id, $photo->id))->handle(
            app(DatabaseTenantContext::class),
            app(AuditRecorder::class),
            app(Dispatcher::class),
        );

        Notification::assertSentTo([$owner, $administrator, $admitted], EventPhotoContributed::class);
        Notification::assertNotSentTo($uploader, EventPhotoContributed::class);
        Notification::assertNotSentTo($expired, EventPhotoContributed::class);
        Notification::assertNotSentTo($revokedAccount, EventPhotoContributed::class);
        Notification::assertNotSentTo($revokedAdmission, EventPhotoContributed::class);
        Notification::assertNotSentTo($unrelated, EventPhotoContributed::class);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'event.contribution_notified', 'subject_id' => $event->id,
        ]);
    }

    public function test_notification_retry_resumes_without_resending_successful_recipients(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'notification-retry']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$administrator] = $this->membership($family, FamilySpaceRole::Administrator, 'Administrator');
        [$uploader] = $this->membership($family, FamilySpaceRole::Guest, 'Uploader');
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Wedding',
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $uploader->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id, 'created_by' => $uploader->id,
            'media_upload_id' => $upload->id,
        ]);
        $context = TenantOperationContext::forBackground($family->id, $uploader->id);
        $job = new SendEventContributionNotifications($context->toArray(), $event->id, $photo->id);
        $dispatcher = new PartiallyFailingNotificationDispatcher($administrator->id);

        try {
            $job->handle(app(DatabaseTenantContext::class), app(AuditRecorder::class), $dispatcher);
            $this->fail('The first delivery pass unexpectedly completed.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated notification failure.', $exception->getMessage());
        }

        $job->handle(app(DatabaseTenantContext::class), app(AuditRecorder::class), $dispatcher);

        $this->assertSame(1, $dispatcher->successfulDeliveries[$owner->id] ?? 0);
        $this->assertSame(1, $dispatcher->successfulDeliveries[$administrator->id] ?? 0);
        $this->assertSame(2, $dispatcher->attempts[$administrator->id] ?? 0);
        $this->assertDatabaseCount('event_notification_deliveries', 2);
        $this->assertSame(2, DB::table('event_notification_deliveries')->whereNotNull('sent_at')->count());
    }

    public function test_idempotent_contribution_finalisation_dispatches_one_notification_job(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Party',
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'event_id' => $event->id, 'name' => 'Party photos',
            'visibility' => AlbumVisibility::FamilySpace,
            'guest_participation' => GuestParticipation::None,
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $owner->id,
            'target_album_id' => $album->id, 'state' => MediaUploadState::Ready,
        ]);
        $context = TenantOperationContext::forBackground($family->id, $owner->id);

        DB::transaction(function () use ($upload, $context): void {
            app(AlbumContributionFinalizer::class)->finalize($upload, $context);
            app(AlbumContributionFinalizer::class)->finalize($upload, $context);
        });

        Queue::assertPushed(SendEventContributionNotifications::class, 1);
    }

    public function test_archive_is_manager_only_and_contains_distinct_originals_and_manifest_metadata(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'event-export']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$member] = $this->membership($family, FamilySpaceRole::Member, 'Member');
        [$guest] = $this->membership($family, FamilySpaceRole::Guest, 'Guest');
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $member->id,
            'name' => 'Golden wedding', 'location' => 'York',
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'event_id' => $event->id, 'name' => 'Celebration',
            'visibility' => AlbumVisibility::FamilySpace,
            'guest_participation' => GuestParticipation::View,
        ]);
        $bytes = "preserved-original\0bytes";
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => MediaUploadState::Ready,
            'original_object_key' => "families/{$family->id}/media/source/original.jpg",
            'original_sha256' => hash('sha256', $bytes),
            'detected_mime_type' => 'image/jpeg',
            'byte_size' => strlen($bytes),
            'client_filename' => 'grandparents-original.jpg',
        ]);
        $this->storage->objects[$upload->original_object_key] = $bytes;
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $member->id,
            'primary_event_id' => $event->id,
            'caption' => 'The family together',
            'archive_source_description' => 'Scanned from the blue album',
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id,
        ]);
        $base = "/api/families/{$family->slug}/events/{$event->id}/exports";

        $this->actingAs($member)->postJson($base)->assertForbidden();
        $this->actingAs($guest)->postJson($base)->assertForbidden();
        $exportId = $this->actingAs($owner)->postJson($base)
            ->assertAccepted()->assertJsonPath('data.state', 'pending')->json('data.id');
        Queue::assertPushed(GenerateEventExport::class, fn ($job): bool => $job->eventExportId === $exportId);

        $context = TenantOperationContext::forBackground($family->id, $owner->id);
        app(EventExportManager::class)->generate($context, $exportId);
        $export = EventExport::query()->findOrFail($exportId);
        $this->assertSame(EventExportState::Ready, $export->state);
        $this->assertSame(1, $export->photo_count);
        $firstArchiveBytes = $this->storage->objects[$export->object_key];
        $export->update(['state' => EventExportState::Processing]);
        app(EventExportManager::class)->generate($context, $exportId);
        $this->assertSame($firstArchiveBytes, $this->storage->objects[$export->object_key]);
        $export->refresh();

        $zipPath = tempnam(sys_get_temp_dir(), 'fambam-export-test-');
        $this->assertNotFalse($zipPath);
        file_put_contents($zipPath, $this->storage->objects[$export->object_key]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($bytes, $zip->getFromName("originals/{$photo->id}.jpg"));
        $this->assertSame(1, $manifest['schema_version']);
        $this->assertSame($event->id, $manifest['event']['id']);
        $this->assertCount(1, $manifest['photos']);
        $this->assertSame(hash('sha256', $bytes), $manifest['photos'][0]['sha256']);
        $this->assertSame($member->id, $manifest['photos'][0]['uploader']['id']);
        $this->assertSame('single', $manifest['photos'][0]['upload_method']);
        $this->assertSame('Scanned from the blue album', $manifest['photos'][0]['provenance']['archive_source_description']);
        $zip->close();
        unlink($zipPath);

        $this->actingAs($owner)->getJson("{$base}/{$export->id}/download")
            ->assertOk()->assertJsonPath('data.url', "https://storage.test/{$export->object_key}");
        $this->assertDatabaseHas('audit_events', ['action' => 'event_export.download_authorised']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'event_export.downloaded']);
        $export->update(['expires_at' => now()->subMinute()]);
        app(EventExportManager::class)->expire($context, $export->id);
        $this->assertSame(EventExportState::Expired, $export->refresh()->state);
        $this->assertContains($export->object_key, $this->storage->deleted);
    }

    public function test_archive_generation_fails_closed_on_original_checksum_mismatch(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Birthday',
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $owner->id,
            'original_object_key' => "families/{$family->id}/media/source/original.png",
            'original_sha256' => hash('sha256', 'expected'),
            'detected_mime_type' => 'image/png',
        ]);
        $this->storage->objects[$upload->original_object_key] = 'tampered';
        Photo::factory()->create([
            'family_space_id' => $family->id, 'media_upload_id' => $upload->id,
            'created_by' => $owner->id, 'primary_event_id' => $event->id,
        ]);
        $export = EventExport::query()->create([
            'family_space_id' => $family->id, 'event_id' => $event->id,
            'requested_by' => $owner->id, 'state' => EventExportState::Pending,
            'object_key' => "families/{$family->id}/event-exports/{$event->id}/failure.zip",
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('integrity check');
        app(EventExportManager::class)->generate(
            TenantOperationContext::forBackground($family->id, $owner->id),
            $export->id,
        );
    }

    /** @return array{User, FamilySpaceMembership} */
    private function membership(FamilySpace $family, FamilySpaceRole $role, string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role,
        ]);

        return [$user, $membership];
    }
}

class EventExportStorage implements MediaObjectStorage
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var list<string> */
    public array $deleted = [];

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        return new UploadAuthorization('https://storage.test/write', [], CarbonImmutable::instance($expiresAt));
    }

    public function inspect(string $key): ?StoredObject
    {
        return isset($this->objects[$key])
            ? new StoredObject(strlen($this->objects[$key]), hash('sha256', $this->objects[$key]))
            : null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        if (! isset($this->objects[$key])) {
            throw new RuntimeException('Missing test object.');
        }
        file_put_contents($localPath, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $bytes = file_get_contents($localPath);
        if ($bytes === false || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Invalid test object.');
        }
        if (isset($this->objects[$key]) && $this->objects[$key] !== $bytes) {
            throw new RuntimeException('Test object collision.');
        }
        $this->objects[$key] = $bytes;
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
        unset($this->objects[$key]);
    }
}

class EventExportUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        return new MediaDeliveryAuthorization("https://storage.test/{$key}", CarbonImmutable::instance($expiresAt));
    }
}

class PartiallyFailingNotificationDispatcher implements Dispatcher
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
