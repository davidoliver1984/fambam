<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Jobs\ValidateMediaUpload;
use App\Media\MediaObjectStorage;
use App\Media\StoredObject;
use App\Media\UploadAuthorization;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private FakeMediaObjectStorage $storage;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->storage = new FakeMediaObjectStorage;
        $this->app->instance(MediaObjectStorage::class, $this->storage);
    }

    public function test_owner_initiates_a_tenant_scoped_write_once_upload(): void
    {
        [$familySpace, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'media-family');

        $response = $this->actingAs($owner)
            ->withHeader('Idempotency-Key', 'photo-1')
            ->postJson('/api/families/media-family/media-uploads', [
                'client_filename' => '../../holiday.JPEG',
                'client_mime_type' => 'image/jpeg',
            ])
            ->assertCreated()
            ->assertJsonPath('data.state', 'initiated')
            ->assertJsonPath('data.upload_authorization.method', 'PUT')
            ->assertJsonPath('data.upload_authorization.headers.If-None-Match', '*');

        $upload = MediaUpload::query()->findOrFail($response->json('data.id'));
        $this->assertSame(
            "families/{$familySpace->id}/media-staging/{$upload->id}/original",
            $upload->staging_object_key,
        );
        $this->assertStringNotContainsString('JPEG', $upload->staging_object_key);
        $this->assertSame($upload->staging_object_key, $this->storage->authorizedKeys[0]);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $familySpace->id,
            'actor_user_id' => $owner->id,
            'action' => 'media_upload.initiated',
            'subject_id' => $upload->id,
        ]);
        $event = AuditEvent::query()->where('subject_id', $upload->id)->sole();
        $this->assertSame($upload->correlation_id, $event->correlation_id);
        $this->assertSame($upload->traceparent, $event->traceparent);
    }

    public function test_initiation_is_idempotent_and_rejects_key_reuse_with_different_input(): void
    {
        [, $member] = $this->familyWithRole(FamilySpaceRole::Member, 'retry-family');
        $request = ['client_filename' => 'same.jpg', 'client_mime_type' => 'image/jpeg'];

        $first = $this->actingAs($member)->withHeader('Idempotency-Key', 'same-key')
            ->postJson('/api/families/retry-family/media-uploads', $request)
            ->assertCreated();
        $second = $this->actingAs($member)->withHeader('Idempotency-Key', 'same-key')
            ->postJson('/api/families/retry-family/media-uploads', $request)
            ->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, MediaUpload::query()->count());
        $this->assertDatabaseCount('audit_events', 1);

        $this->actingAs($member)->withHeader('Idempotency-Key', 'same-key')
            ->postJson('/api/families/retry-family/media-uploads', [
                'client_filename' => 'different.png',
                'client_mime_type' => 'image/png',
            ])
            ->assertUnprocessable();
    }

    public function test_completion_inspects_storage_and_repeated_completion_is_a_no_op(): void
    {
        [, $member] = $this->familyWithRole(FamilySpaceRole::Member, 'completion-family');
        $created = $this->actingAs($member)->withHeader('Idempotency-Key', 'complete-key')
            ->postJson('/api/families/completion-family/media-uploads', [
                'client_filename' => 'photo.webp',
                'client_mime_type' => 'image/webp',
            ])->json('data');
        $upload = MediaUpload::query()->findOrFail($created['id']);
        $this->storage->objects[$upload->staging_object_key] = new StoredObject(2048);

        $endpoint = "/api/families/completion-family/media-uploads/{$upload->id}/complete";
        $this->actingAs($member)->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.state', 'uploaded')
            ->assertJsonPath('data.byte_size', 2048);
        $uploadedAt = $upload->refresh()->uploaded_at;

        $this->actingAs($member)->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.state', 'uploaded');

        $this->assertSame(1, $this->storage->inspectionCount);
        $this->assertTrue($uploadedAt?->equalTo($upload->refresh()->uploaded_at) ?? false);
        Queue::assertPushed(ValidateMediaUpload::class, 1);
    }

    public function test_completion_rejects_missing_empty_and_oversized_objects(): void
    {
        config(['media.upload.max_bytes' => 10]);
        [, $member] = $this->familyWithRole(FamilySpaceRole::Member, 'invalid-object-family');

        foreach ([null, new StoredObject(0), new StoredObject(11)] as $index => $object) {
            $created = $this->actingAs($member)->withHeader('Idempotency-Key', "invalid-{$index}")
                ->postJson('/api/families/invalid-object-family/media-uploads', [
                    'client_filename' => "photo-{$index}.jpg",
                ])->json('data');
            $upload = MediaUpload::query()->findOrFail($created['id']);
            if ($object !== null) {
                $this->storage->objects[$upload->staging_object_key] = $object;
            }

            $this->actingAs($member)
                ->postJson("/api/families/invalid-object-family/media-uploads/{$upload->id}/complete")
                ->assertUnprocessable();
            $this->assertSame(MediaUploadState::Initiated, $upload->refresh()->state);
        }
    }

    public function test_contributor_and_guest_have_no_phase_five_upload_path(): void
    {
        foreach ([FamilySpaceRole::Contributor, FamilySpaceRole::Guest] as $index => $role) {
            [, $user] = $this->familyWithRole($role, "deferred-role-{$index}");
            $this->actingAs($user)->withHeader('Idempotency-Key', "denied-{$index}")
                ->postJson("/api/families/deferred-role-{$index}/media-uploads", [
                    'client_filename' => 'photo.jpg',
                ])->assertForbidden();
        }
    }

    public function test_an_upload_cannot_be_completed_from_another_family_or_account(): void
    {
        [$firstFamily, $firstMember] = $this->familyWithRole(FamilySpaceRole::Member, 'first-media-family');
        [, $secondMember] = $this->familyWithRole(FamilySpaceRole::Member, 'second-media-family');
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $firstFamily->id,
            'user_id' => $secondMember->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $created = $this->actingAs($firstMember)->withHeader('Idempotency-Key', 'private-upload')
            ->postJson('/api/families/first-media-family/media-uploads', [
                'client_filename' => 'private.jpg',
            ])->json('data');

        $this->actingAs($secondMember)
            ->postJson("/api/families/first-media-family/media-uploads/{$created['id']}/complete")
            ->assertForbidden();
        $this->actingAs($secondMember)
            ->postJson("/api/families/second-media-family/media-uploads/{$created['id']}/complete")
            ->assertNotFound();
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $familySpace = FamilySpace::factory()->create(['slug' => $slug]);
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$familySpace, $user];
    }
}

class FakeMediaObjectStorage implements MediaObjectStorage
{
    /** @var list<string> */
    public array $authorizedKeys = [];

    /** @var array<string, StoredObject> */
    public array $objects = [];

    public int $inspectionCount = 0;

    public function authorizeSingleWrite(string $key, DateTimeInterface $expiresAt): UploadAuthorization
    {
        $this->authorizedKeys[] = $key;

        return new UploadAuthorization(
            "https://storage.test/{$key}",
            ['If-None-Match' => '*'],
            CarbonImmutable::instance($expiresAt),
        );
    }

    public function inspect(string $key): ?StoredObject
    {
        $this->inspectionCount++;

        return $this->objects[$key] ?? null;
    }

    public function downloadTo(string $key, string $localPath): void
    {
        file_put_contents($localPath, 'fake');
    }

    public function finalizeWriteOnce(string $localPath, string $key, string $sha256): void
    {
        $this->objects[$key] = new StoredObject((int) filesize($localPath), $sha256);
    }

    public function delete(string $key): void
    {
        unset($this->objects[$key]);
    }
}
