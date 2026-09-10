<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilyExportScope;
use App\Enums\FamilyExportState;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Exports\FamilyArchiveBuilder;
use App\Jobs\GenerateFamilyExport;
use App\Jobs\SendFamilyExportNotification;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Media\MediaSigningAudience;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilyExport;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\NotificationCandidate;
use App\Models\NotificationDelivery;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoPerson;
use App\Models\PhotoStory;
use App\Models\SavedSearch;
use App\Models\User;
use App\Notifications\FamilyActivityNotification;
use App\Services\FamilyExportManager;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class FamilyExportHttpTest extends TestCase
{
    use RefreshDatabase;

    private FamilyExportTestStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = new FamilyExportTestStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
        $this->app->instance(MediaDeliveryUrlSigner::class, new FamilyExportTestUrlSigner);
    }

    public function test_full_export_is_owner_only_and_personal_export_is_requester_owned(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['slug' => 'export-family']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$administrator] = $this->membership($family, FamilySpaceRole::Administrator, 'Administrator');
        [$contributor] = $this->membership($family, FamilySpaceRole::Contributor, 'Contributor');
        $base = '/api/families/export-family/exports';

        $this->actingAs($administrator)->postJson("{$base}/full")->assertForbidden();
        $fullId = $this->actingAs($owner)->postJson("{$base}/full")
            ->assertAccepted()->assertJsonPath('data.scope', 'family_space_full')->json('data.id');
        $personalId = $this->actingAs($contributor)->postJson("{$base}/personal")
            ->assertAccepted()->assertJsonPath('data.scope', 'personal')->json('data.id');
        $otherFamily = FamilySpace::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $otherFamily->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);
        $otherExport = $this->readyExport($otherFamily, $owner, FamilyExportScope::FamilySpaceFull);

        Queue::assertPushed(GenerateFamilyExport::class, 2);
        $this->actingAs($owner)->getJson($base)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $fullId);
        $this->actingAs($owner)->getJson("{$base}/{$otherExport->id}")->assertNotFound();
        $this->actingAs($contributor)->getJson("{$base}/{$fullId}")->assertNotFound();
        $this->actingAs($contributor)->getJson("{$base}/{$personalId}")
            ->assertOk()->assertJsonPath('data.state', 'pending');
    }

    public function test_personal_selection_keeps_photo_visibility_separate_from_original_authority(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$member] = $this->membership($family, FamilySpaceRole::Member, 'Member');
        $owned = $this->photo($family, $member, PhotoVisibility::Private);
        $widened = $this->photo($family, $owner, PhotoVisibility::Private);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $member->id,
            'name' => 'My album',
            'visibility' => AlbumVisibility::FamilySpace,
        ]);
        $album->photos()->attach($widened->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $member->id,
        ]);
        $deleted = $this->photo($family, $member, PhotoVisibility::Private);
        $deleted->delete();
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $member->id,
            'scope' => FamilyExportScope::Personal,
            'state' => FamilyExportState::Pending,
            'object_key' => "families/{$family->id}/family-exports/test.zip",
        ]);

        $selection = app(FamilyExportManager::class)->beginGeneration(
            TenantOperationContext::forBackground($family->id, $member->id),
            $export->id,
        );

        $this->assertNotNull($selection);
        $this->assertContains($owned->id, $selection->photoIds);
        $this->assertContains($widened->id, $selection->photoIds);
        $this->assertNotContains($deleted->id, $selection->photoIds);
        $this->assertContains($owned->id, $selection->originalPhotoIds);
        $this->assertNotContains($widened->id, $selection->originalPhotoIds);
    }

    public function test_download_rechecks_requester_membership_and_full_export_owner_role(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'download-family']);
        [$owner, $membership] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $full = $this->readyExport($family, $owner, FamilyExportScope::FamilySpaceFull);
        $personal = $this->readyExport($family, $owner, FamilyExportScope::Personal);
        $base = '/api/families/download-family/exports';

        $this->actingAs($owner)->getJson("{$base}/{$full->id}/download")
            ->assertOk()->assertJsonPath('data.url', "https://storage.test/{$full->object_key}");
        $membership->update(['role' => FamilySpaceRole::Contributor]);
        $this->actingAs($owner)->getJson("{$base}/{$full->id}/download")->assertForbidden();
        $this->actingAs($owner)->getJson("{$base}/{$personal->id}/download")->assertOk();
        $membership->update(['state' => MembershipState::Removed, 'removed_at' => now()]);
        $this->actingAs($owner)->getJson("{$base}/{$personal->id}/download")->assertNotFound();
    }

    public function test_terminal_transition_records_one_unconditional_notification_intent_and_delivery(): void
    {
        Queue::fake();
        Notification::fake();
        $family = FamilySpace::factory()->create(['slug' => 'export-notifications']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $owner->id,
            'scope' => FamilyExportScope::Personal,
            'state' => FamilyExportState::Processing,
            'object_key' => "families/{$family->id}/family-exports/notify.zip",
        ]);
        $context = TenantOperationContext::forBackground($family->id, $owner->id);
        $manager = app(FamilyExportManager::class);

        $manager->markReady($context, $export->id, str_repeat('a', 64), 42, 3);
        $manager->markReady($context, $export->id, str_repeat('a', 64), 42, 3);
        $this->assertSame(1, NotificationCandidate::query()->where('family_space_id', $family->id)->count());
        Queue::assertPushed(SendFamilyExportNotification::class, 1);

        $manager->deliverTerminalNotification($context, $export->id, FamilyExportState::Ready);
        $manager->deliverTerminalNotification($context, $export->id, FamilyExportState::Ready);
        Notification::assertSentToTimes($owner, FamilyActivityNotification::class, 1);
        $this->assertSame(1, NotificationDelivery::query()->where('family_export_id', $export->id)->count());
        $this->actingAs($owner)->getJson('/api/families/export-notifications/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.category', 'export')
            ->assertJsonPath('data.0.family_export_id', $export->id);
    }

    public function test_failed_and_later_ready_outcomes_have_distinct_idempotent_notification_intents(): void
    {
        Queue::fake();
        Notification::fake();
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $owner->id,
            'scope' => FamilyExportScope::Personal,
            'state' => FamilyExportState::Processing,
            'object_key' => "families/{$family->id}/family-exports/retry.zip",
        ]);
        $context = TenantOperationContext::forBackground($family->id, $owner->id);
        $manager = app(FamilyExportManager::class);

        $manager->markFailed($context, $export->id);
        $this->assertNotNull($manager->beginGeneration($context, $export->id));
        $manager->markReady($context, $export->id, str_repeat('b', 64), 84, 6);

        $candidates = NotificationCandidate::query()
            ->where('family_space_id', $family->id)->orderBy('source_action_id')->get();
        $this->assertCount(2, $candidates);
        $this->assertNotSame($candidates[0]->source_action_id, $candidates[1]->source_action_id);
        Queue::assertPushed(SendFamilyExportNotification::class, 2);

        $manager->deliverTerminalNotification($context, $export->id, FamilyExportState::Failed);
        $manager->deliverTerminalNotification($context, $export->id, FamilyExportState::Ready);
        Notification::assertSentToTimes($owner, FamilyActivityNotification::class, 2);
        $this->assertSame(2, NotificationDelivery::query()->where('family_export_id', $export->id)->count());
    }

    public function test_full_selection_includes_preserved_unpromoted_uploads_and_recoverable_photos(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $deletedPhoto = $this->photo($family, $owner, PhotoVisibility::FamilySpace);
        $deletedPhoto->delete();
        $qualifying = [];
        foreach ([
            MediaUploadState::Preserved,
            MediaUploadState::Processing,
            MediaUploadState::Ready,
            MediaUploadState::Degraded,
        ] as $state) {
            $qualifying[] = MediaUpload::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $owner->id,
                'state' => $state,
                'original_object_key' => "families/{$family->id}/media/".Str::uuid().'.jpg',
                'original_sha256' => hash('sha256', Str::uuid()->toString()),
            ])->id;
        }
        $excluded = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'state' => MediaUploadState::Quarantined,
            'original_object_key' => "families/{$family->id}/quarantine/".Str::uuid().'.jpg',
            'original_sha256' => hash('sha256', 'quarantined'),
        ]);
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $owner->id,
            'scope' => FamilyExportScope::FamilySpaceFull,
            'state' => FamilyExportState::Pending,
            'object_key' => "families/{$family->id}/family-exports/full-selection.zip",
        ]);

        $selection = app(FamilyExportManager::class)->beginGeneration(
            TenantOperationContext::forBackground($family->id, $owner->id),
            $export->id,
        );

        $this->assertNotNull($selection);
        $this->assertContains($deletedPhoto->id, $selection->photoIds);
        $this->assertEqualsCanonicalizing($qualifying, $selection->unattachedMediaUploadIds);
        $this->assertNotContains($excluded->id, $selection->unattachedMediaUploadIds);
    }

    public function test_personal_selection_does_not_export_album_scoped_interactions_after_album_access_is_revoked(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$contributor, $membership] = $this->membership($family, FamilySpaceRole::Contributor, 'Contributor');
        $photo = $this->photo($family, $contributor, PhotoVisibility::Private);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Revoked album',
            'visibility' => AlbumVisibility::Selected,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
        ]);
        $grant = AlbumGrant::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'family_space_membership_id' => $membership->id,
            'can_view' => true,
            'can_contribute' => true,
            'granted_by' => $owner->id,
        ]);
        $comment = PhotoComment::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'album_id' => $album->id,
            'author_id' => $contributor->id,
            'body' => 'Visible only while the AlbumGrant is active.',
        ]);
        $grant->delete();
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $contributor->id,
            'scope' => FamilyExportScope::Personal,
            'state' => FamilyExportState::Pending,
            'object_key' => "families/{$family->id}/family-exports/revoked-interaction.zip",
        ]);

        $selection = app(FamilyExportManager::class)->beginGeneration(
            TenantOperationContext::forBackground($family->id, $contributor->id),
            $export->id,
        );

        $this->assertNotNull($selection);
        $this->assertContains($photo->id, $selection->photoIds);
        $this->assertNotContains($album->id, $selection->albumIds);
        $this->assertNotContains($comment->id, $selection->commentIds);
    }

    public function test_full_generation_seals_the_versioned_manifest_with_verified_originals_and_checksums(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create(['name' => 'Archive family']);
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        $photo = $this->photo($family, $owner, PhotoVisibility::FamilySpace);
        $photo->delete();
        $unattachedBytes = 'unattached-preserved-original';
        $unattached = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'state' => MediaUploadState::Degraded,
            'original_object_key' => "families/{$family->id}/media/unattached.png",
            'original_sha256' => hash('sha256', $unattachedBytes),
            'detected_mime_type' => 'image/png',
        ]);
        $this->storage->objects[$unattached->original_object_key] = $unattachedBytes;
        $export = FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $owner->id,
            'scope' => FamilyExportScope::FamilySpaceFull,
            'state' => FamilyExportState::Pending,
            'object_key' => "families/{$family->id}/family-exports/full.zip",
        ]);

        app(FamilyExportManager::class)->generate(
            TenantOperationContext::forBackground($family->id, $owner->id),
            $export->id,
        );

        $export->refresh();
        $this->assertSame(FamilyExportState::Ready, $export->state);
        $this->assertSame(hash('sha256', $this->storage->finalized[$export->object_key]), $export->archive_sha256);
        $archive = $this->archive($export->object_key);
        $photos = json_decode($archive->getFromName('photos.json'), true, flags: JSON_THROW_ON_ERROR);
        $manifest = json_decode($archive->getFromName('manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        $checksums = json_decode($archive->getFromName('checksums.json'), true, flags: JSON_THROW_ON_ERROR)['files'];
        $this->assertSame('family_space_full', $manifest['export_scope']);
        $this->assertArrayNotHasKey('archive_sha256', $manifest);
        $this->assertSame($photo->deleted_at?->toJSON(), $photos['items'][0]['deleted_at']);
        $this->assertTrue($photos['items'][0]['original_included']);
        $this->assertSame('family-memory.jpg', $photos['items'][0]['original_media']['client_filename']);
        $this->assertNotFalse($archive->locateName("media/originals/{$photo->id}.jpg"));
        $this->assertNotFalse($archive->locateName("media/unattached/{$unattached->id}.png"));
        $this->assertFalse($archive->locateName("media/unattached/{$photo->media_upload_id}.jpg"));
        $this->assertArrayNotHasKey('checksums.json', $checksums);
        $this->assertCount($archive->numFiles - 1, $checksums);
        $this->assertStringNotContainsString('recognition_allowed', (string) $archive->getFromName('people.json'));
        $this->assertStringNotContainsString('face_', (string) $archive->getFromName('photos.json'));
        foreach ($checksums as $path => $checksum) {
            $this->assertSame($checksum, hash('sha256', $archive->getFromName($path)));
        }
        $archive->close();
    }

    public function test_personal_final_reconciliation_adds_newly_authorized_original_and_removes_revoked_original(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$member] = $this->membership($family, FamilySpaceRole::Member, 'Member');
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $member->id,
            'name' => 'Member context',
            'visibility' => AlbumVisibility::FamilySpace,
        ]);
        $photo = $this->photo($family, $owner, PhotoVisibility::Private);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $member->id,
        ]);
        $grantExport = $this->pendingExport($family, $member, 'newly-authorized.zip');
        $context = TenantOperationContext::forBackground($family->id, $member->id);
        $grantSelection = app(FamilyExportManager::class)->beginGeneration($context, $grantExport->id);
        $this->assertNotNull($grantSelection);
        $this->assertNotContains($photo->id, $grantSelection->originalPhotoIds);
        $photo->update(['visibility' => PhotoVisibility::FamilySpace]);
        app(FamilyArchiveBuilder::class)->buildAndStore($context, $grantExport, $member, $grantSelection);
        $archive = $this->archive($grantExport->object_key);
        $photos = json_decode($archive->getFromName('photos.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($photos['items'][0]['original_included']);
        $this->assertNotFalse($archive->locateName("media/originals/{$photo->id}.jpg"));
        $archive->close();

        $revokeExport = $this->pendingExport($family, $member, 'revoked-original.zip');
        $revokeSelection = app(FamilyExportManager::class)->beginGeneration($context, $revokeExport->id);
        $this->assertNotNull($revokeSelection);
        $this->assertContains($photo->id, $revokeSelection->originalPhotoIds);
        $photo->update(['visibility' => PhotoVisibility::Private]);
        app(FamilyArchiveBuilder::class)->buildAndStore($context, $revokeExport, $member, $revokeSelection);
        $archive = $this->archive($revokeExport->object_key);
        $photos = json_decode($archive->getFromName('photos.json'), true, flags: JSON_THROW_ON_ERROR);
        $checksums = json_decode($archive->getFromName('checksums.json'), true, flags: JSON_THROW_ON_ERROR)['files'];
        $this->assertFalse($photos['items'][0]['original_included']);
        $this->assertArrayNotHasKey('original_path', $photos['items'][0]);
        $this->assertFalse($archive->locateName("media/originals/{$photo->id}.jpg"));
        $this->assertArrayNotHasKey("media/originals/{$photo->id}.jpg", $checksums);
        $archive->close();
    }

    public function test_contributor_personal_archive_keeps_granted_photo_context_without_disclosing_original(): void
    {
        Queue::fake();
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$contributor, $membership] = $this->membership($family, FamilySpaceRole::Contributor, 'Contributor');
        $photo = $this->photo($family, $owner, PhotoVisibility::Private);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Granted memories',
            'visibility' => AlbumVisibility::Selected,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $owner->id,
        ]);
        AlbumGrant::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'family_space_membership_id' => $membership->id,
            'can_view' => true,
            'can_contribute' => true,
            'granted_by' => $owner->id,
        ]);
        PhotoComment::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'album_id' => $album->id,
            'author_id' => $contributor->id,
            'body' => 'My contribution keeps the Photo as context.',
        ]);
        $export = $this->pendingExport($family, $contributor, 'contributor.zip');

        app(FamilyExportManager::class)->generate(
            TenantOperationContext::forBackground($family->id, $contributor->id),
            $export->id,
        );

        $archive = $this->archive($export->object_key);
        $photos = json_decode($archive->getFromName('photos.json'), true, flags: JSON_THROW_ON_ERROR);
        $checksums = json_decode($archive->getFromName('checksums.json'), true, flags: JSON_THROW_ON_ERROR)['files'];
        $this->assertSame($photo->id, $photos['items'][0]['id']);
        $this->assertFalse($photos['items'][0]['original_included']);
        $this->assertArrayNotHasKey('original_media', $photos['items'][0]);
        $this->assertArrayNotHasKey("media/originals/{$photo->id}.jpg", $checksums);
        $this->assertFalse($archive->locateName("media/originals/{$photo->id}.jpg"));
        $archive->close();
    }

    public function test_personal_final_reconciliation_removes_revoked_photo_context_and_marks_saved_search_reference_unresolved(): void
    {
        $family = FamilySpace::factory()->create();
        [$owner] = $this->membership($family, FamilySpaceRole::Owner, 'Owner');
        [$member] = $this->membership($family, FamilySpaceRole::Member, 'Member');
        $photo = $this->photo($family, $owner, PhotoVisibility::Private);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $member->id,
            'name' => 'Temporary context',
            'visibility' => AlbumVisibility::Selected,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
            'position' => 1, 'added_by' => $member->id,
        ]);
        $story = PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'author_id' => $owner->id,
            'body' => 'Context removed with the Photo.',
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $photoPerson = PhotoPerson::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'proposal_source' => 'manual',
            'status' => 'approved',
            'proposed_by' => $member->id,
            'resolved_by' => $member->id,
            'resolved_at' => now(),
        ]);
        $savedSearch = SavedSearch::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $member->id,
            'name' => 'Person memories',
            'filters' => ['schema_version' => 1],
        ]);
        $savedSearch->people()->attach($person->id, ['family_space_id' => $family->id]);
        $export = $this->pendingExport($family, $member, 'reconciled-down.zip');
        $context = TenantOperationContext::forBackground($family->id, $member->id);
        $selection = app(FamilyExportManager::class)->beginGeneration($context, $export->id);
        $this->assertNotNull($selection);
        $this->assertContains($story->id, $selection->storyIds);

        $album->delete();
        $photoPerson->delete();
        app(FamilyArchiveBuilder::class)->buildAndStore($context, $export, $member, $selection);

        $archive = $this->archive($export->object_key);
        $photos = json_decode($archive->getFromName('photos.json'), true, flags: JSON_THROW_ON_ERROR);
        $stories = json_decode($archive->getFromName('stories.json'), true, flags: JSON_THROW_ON_ERROR);
        $searches = json_decode($archive->getFromName('saved_searches.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([], $photos['items']);
        $this->assertSame([], $stories['items']);
        $this->assertSame([['person_id' => $person->id, 'resolved' => false]], $searches['items'][0]['people']);
        $archive->close();
    }

    /** @return array{User, FamilySpaceMembership} */
    private function membership(FamilySpace $family, FamilySpaceRole $role, string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$user, $membership];
    }

    private function photo(FamilySpace $family, User $creator, PhotoVisibility $visibility): Photo
    {
        $bytes = "original-{$creator->id}-".Str::uuid();
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => MediaUploadState::Ready,
            'original_object_key' => "families/{$family->id}/media/".Str::uuid().'.jpg',
            'original_sha256' => hash('sha256', $bytes),
            'detected_mime_type' => 'image/jpeg',
            'client_filename' => 'family-memory.jpg',
        ]);

        $this->storage->objects[$upload->original_object_key] = $bytes;

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
        ]);
    }

    private function pendingExport(FamilySpace $family, User $requester, string $filename): FamilyExport
    {
        return FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $requester->id,
            'scope' => FamilyExportScope::Personal,
            'state' => FamilyExportState::Pending,
            'object_key' => "families/{$family->id}/family-exports/{$filename}",
        ]);
    }

    private function archive(string $objectKey): ZipArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'family-export-test-');
        $this->assertNotFalse($path);
        file_put_contents($path, $this->storage->finalized[$objectKey]);
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path));
        @unlink($path);

        return $archive;
    }

    private function readyExport(FamilySpace $family, User $requester, FamilyExportScope $scope): FamilyExport
    {
        return FamilyExport::query()->create([
            'family_space_id' => $family->id,
            'requested_by' => $requester->id,
            'scope' => $scope,
            'state' => FamilyExportState::Ready,
            'object_key' => "families/{$family->id}/family-exports/".Str::uuid().'.zip',
            'archive_sha256' => str_repeat('a', 64),
            'byte_size' => 42,
            'expires_at' => now()->addHour(),
        ]);
    }
}

class FamilyExportTestUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(string $key, string $responseContentType, \DateTimeInterface $expiresAt, MediaSigningAudience $audience): MediaDeliveryAuthorization
    {
        return new MediaDeliveryAuthorization("https://storage.test/{$key}", CarbonImmutable::instance($expiresAt));
    }
}

class FamilyExportTestStorage implements MediaObjectStorage
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, string> */
    public array $finalized = [];

    public function authorizeSingleWrite(string $key, \DateTimeInterface $expiresAt, MediaSigningAudience $audience): UploadAuthorization
    {
        throw new \RuntimeException('Not used.');
    }

    public function inspect(string $key): ?StoredObject
    {
        return null;
    }

    public function downloadTo(string $key, string $path): void
    {
        if (! array_key_exists($key, $this->objects)) {
            throw new \RuntimeException('Missing test object.');
        }
        file_put_contents($path, $this->objects[$key]);
    }

    public function finalizeWriteOnce(string $sourcePath, string $key, string $sha256): void
    {
        $bytes = file_get_contents($sourcePath);
        if ($bytes === false || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new \RuntimeException('Invalid test finalization.');
        }
        if (isset($this->finalized[$key]) && $this->finalized[$key] !== $bytes) {
            throw new \RuntimeException('Test object replacement refused.');
        }
        $this->finalized[$key] = $bytes;
    }

    public function delete(string $key): void
    {
        $this->deleted[] = $key;
    }
}
