<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Jobs\DeleteFamilySpace;
use App\Media\FamilyMediaStorageCleaner;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\PerceptualHash;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
use App\Models\Tag;
use App\Models\User;
use App\Services\FamilySpaceDeletionManager;
use App\Storage\FamilyStorageKey;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Tests\TestCase;

class FamilySpaceDeletionTest extends TestCase
{
    use RefreshDatabase;

    private FakeFamilyMediaStorageCleaner $mediaCleaner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mediaCleaner = new FakeFamilyMediaStorageCleaner;
        $this->app->instance(FamilyMediaStorageCleaner::class, $this->mediaCleaner);
    }

    public function test_only_an_owner_can_request_and_cancel_deletion(): void
    {
        Carbon::setTestNow('2026-08-05 10:00:00');
        [$familySpace, $owner] = $this->familyWithOwner('deletion-family');
        $administrator = $this->addMember($familySpace, FamilySpaceRole::Administrator);

        $this->actingAs($administrator)
            ->postJson('/api/families/deletion-family/deletion')
            ->assertForbidden();

        $response = $this->actingAs($owner)
            ->withHeaders(['X-Correlation-ID' => 'deletion-request-correlation'])
            ->postJson('/api/families/deletion-family/deletion')
            ->assertOk()
            ->assertJsonPath('data.status', FamilySpaceStatus::DeletionRequested->value)
            ->assertJsonPath('data.deletion.scheduled_at', '2026-08-19T10:00:00+00:00');

        $this->assertSame('deletion-request-correlation', $response->headers->get('X-Correlation-ID'));
        $familySpace->refresh();
        $this->assertSame(FamilySpaceStatus::DeletionRequested, $familySpace->status);
        $this->assertSame($owner->id, $familySpace->deletion_requested_by);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $familySpace->id,
            'actor_user_id' => $owner->id,
            'correlation_id' => 'deletion-request-correlation',
            'action' => 'family_space.deletion_requested',
        ]);
        $this->assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-[0-9a-f]{2}$/',
            (string) $this->getConnection()->table('audit_events')->value('traceparent'),
        );

        $this->actingAs($administrator)
            ->deleteJson('/api/families/deletion-family/deletion')
            ->assertForbidden();
        $this->actingAs($owner)
            ->deleteJson('/api/families/deletion-family/deletion')
            ->assertOk()
            ->assertJsonPath('data.status', FamilySpaceStatus::Active->value);

        $familySpace->refresh();
        $this->assertSame(FamilySpaceStatus::Active, $familySpace->status);
        $this->assertNull($familySpace->scheduled_deletion_at);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $familySpace->id,
            'actor_user_id' => $owner->id,
            'action' => 'family_space.deletion_cancelled',
        ]);
    }

    public function test_pending_deletion_details_are_visible_only_to_administrators_and_owners(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('visibility-family');
        $administrator = $this->addMember($familySpace, FamilySpaceRole::Administrator);
        $member = $this->addMember($familySpace, FamilySpaceRole::Member);
        $this->actingAs($owner)->postJson('/api/families/visibility-family/deletion')->assertOk();

        $this->actingAs($administrator)
            ->getJson('/api/families/visibility-family')
            ->assertOk()
            ->assertJsonPath('data.status', FamilySpaceStatus::DeletionRequested->value)
            ->assertJsonStructure(['data' => ['deletion' => ['requested_at', 'scheduled_at']]]);

        $memberPayload = $this->actingAs($member)
            ->getJson('/api/families/visibility-family')
            ->assertOk()
            ->assertJsonPath('data.status', FamilySpaceStatus::Active->value)
            ->json('data');
        $this->assertArrayNotHasKey('deletion', $memberPayload);
    }

    public function test_teardown_is_idempotent_and_marks_deleted_before_removing_owners(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('teardown-family');
        $this->addMember($familySpace, FamilySpaceRole::Member);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
        ]);
        MediaVariant::query()->create([
            'family_space_id' => $familySpace->id,
            'media_upload_id' => $upload->id,
            'transform_name' => 'thumbnail',
            'processing_version' => 1,
            'object_key' => "families/{$familySpace->id}/media/{$upload->id}/variants/thumbnail.v1.webp",
            'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'variant'),
            'pixel_width' => 320,
            'pixel_height' => 320,
            'byte_size' => 100,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $familySpace->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        PerceptualHash::query()->create([
            'family_space_id' => $familySpace->id,
            'media_upload_id' => $upload->id,
            'algorithm' => 'dhash-luma-64',
            'processing_version' => 1,
            'hash_value' => '0000000000000000',
        ]);
        $tag = Tag::query()->create([
            'family_space_id' => $familySpace->id,
            'label' => 'Family',
            'normalized_label' => 'family',
            'created_by' => $owner->id,
        ]);
        $photo->tags()->attach($tag->id, [
            'family_space_id' => $familySpace->id,
            'added_by' => $owner->id,
            'created_at' => now(),
        ]);
        $person = Person::factory()->create(['family_space_id' => $familySpace->id]);
        PhotoMetadataProposal::query()->create([
            'family_space_id' => $familySpace->id,
            'photo_id' => $photo->id,
            'field' => 'location',
            'location_description' => 'Blackpool',
            'status' => 'pending',
            'proposed_by' => $owner->id,
        ]);
        PhotoPerson::query()->create([
            'family_space_id' => $familySpace->id,
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);
        $familySpace->forceFill([
            'status' => FamilySpaceStatus::DeletionRequested,
            'deletion_requested_at' => now()->subDays(15),
            'deletion_requested_by' => $owner->id,
            'scheduled_deletion_at' => now()->subDay(),
        ])->save();
        $context = new TenantOperationContext(
            $familySpace->id,
            $owner->id,
            'teardown-correlation',
            '00-11111111111111111111111111111111-2222222222222222-01',
        );
        $deletions = app(FamilySpaceDeletionManager::class);

        $deletions->teardown($context);
        $deletions->teardown($context);

        $this->assertSame(FamilySpaceStatus::Deleted, $familySpace->refresh()->status);
        $this->assertSame(0, FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('state', MembershipState::Active->value)
            ->count());
        $this->assertSame(1, $this->getConnection()->table('audit_events')
            ->where('action', 'family_space.deleted')
            ->count());
        $this->assertDatabaseMissing('media_uploads', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('media_variants', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('perceptual_hashes', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('photos', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('photo_metadata_proposals', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('photo_people', ['family_space_id' => $familySpace->id]);
        $this->assertDatabaseMissing('tags', ['family_space_id' => $familySpace->id]);
        $this->assertSame([$familySpace->id], $this->mediaCleaner->familySpaceIds);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $familySpace->id,
            'actor_user_id' => $owner->id,
            'correlation_id' => 'teardown-correlation',
            'traceparent' => $context->traceparent,
        ]);
    }

    public function test_teardown_retries_storage_cleanup_before_deleting_media_rows(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('retry-teardown-family');
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
        ]);
        $familySpace->forceFill([
            'status' => FamilySpaceStatus::DeletionRequested,
            'deletion_requested_at' => now()->subDays(15),
            'deletion_requested_by' => $owner->id,
            'scheduled_deletion_at' => now()->subDay(),
        ])->save();
        $context = TenantOperationContext::forBackground($familySpace->id, $owner->id);
        $this->mediaCleaner->failuresRemaining = 1;
        $deletions = app(FamilySpaceDeletionManager::class);

        try {
            $deletions->teardown($context);
            $this->fail('Storage cleanup failure was swallowed.');
        } catch (\RuntimeException) {
            $this->assertSame(FamilySpaceStatus::Deleting, $familySpace->refresh()->status);
            $this->assertDatabaseHas('media_uploads', ['id' => $upload->id]);
        }

        $deletions->teardown($context);

        $this->assertSame(FamilySpaceStatus::Deleted, $familySpace->refresh()->status);
        $this->assertDatabaseMissing('media_uploads', ['id' => $upload->id]);
        $this->assertSame([$familySpace->id, $familySpace->id], $this->mediaCleaner->familySpaceIds);
    }

    public function test_cancelled_deletion_makes_a_stale_teardown_job_a_no_op(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('cancelled-family');
        $context = TenantOperationContext::forBackground($familySpace->id, $owner->id);

        app(DeleteFamilySpace::class, ['context' => $context->toArray()])
            ->handle(app(FamilySpaceDeletionManager::class));

        $this->assertSame(FamilySpaceStatus::Active, $familySpace->refresh()->status);
        $this->assertDatabaseMissing('audit_events', ['action' => 'family_space.deleted']);
    }

    public function test_family_storage_keys_are_tenant_partitioned_and_reject_traversal(): void
    {
        $familySpaceId = '01K1ZZZZZZZZZZZZZZZZZZZZZZ';
        $this->assertSame(
            "families/{$familySpaceId}/photos/original.jpg",
            FamilyStorageKey::for($familySpaceId, 'photos/original.jpg'),
        );

        foreach (['', '..', '../secret', 'photos/../secret', './secret', "bad\0key"] as $unsafe) {
            try {
                FamilyStorageKey::for($familySpaceId, $unsafe);
                $this->fail("Unsafe storage path was accepted: {$unsafe}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array{FamilySpace, User} */
    private function familyWithOwner(string $slug): array
    {
        $familySpace = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);

        return [$familySpace, $owner];
    }

    private function addMember(FamilySpace $familySpace, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}

class FakeFamilyMediaStorageCleaner implements FamilyMediaStorageCleaner
{
    /** @var list<string> */
    public array $familySpaceIds = [];

    public int $failuresRemaining = 0;

    public function deleteFamilyMedia(string $familySpaceId): void
    {
        $this->familySpaceIds[] = $familySpaceId;
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            throw new \RuntimeException('Storage cleanup failed.');
        }
    }
}
