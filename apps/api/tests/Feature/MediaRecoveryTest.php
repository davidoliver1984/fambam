<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Jobs\GenerateCanonicalMediaUpload;
use App\Jobs\GeneratePresentationMediaVariants;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\User;
use App\Tenancy\TenantOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MediaRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_exhausted_canonical_job_marks_only_its_matching_preserved_source_degraded(): void
    {
        [$family, $member, $upload] = $this->upload(MediaUploadState::Preserved, 'canonical-failure');
        $context = TenantOperationContext::forBackground($family->id, $member->id);
        $stale = new GenerateCanonicalMediaUpload(
            $context->toArray(),
            $upload->id,
            hash('sha256', 'stale'),
        );
        $stale->failed(new \RuntimeException('stale worker exhausted'));
        $this->assertSame(MediaUploadState::Preserved, $upload->refresh()->state);

        $job = new GenerateCanonicalMediaUpload(
            $context->toArray(),
            $upload->id,
            (string) $upload->original_sha256,
        );
        $job->failed(new \RuntimeException('worker exhausted'));

        $this->assertSame(MediaUploadState::Degraded, $upload->refresh()->state);
        $this->assertNotNull($upload->original_object_key);
        $this->assertNotNull($upload->original_sha256);
    }

    public function test_canonical_recovery_is_duplicate_safe_and_preserves_the_original_identity(): void
    {
        [$family, $member, $upload] = $this->upload(MediaUploadState::Degraded, 'canonical-retry');
        $endpoint = "/api/families/{$family->slug}/media-uploads/{$upload->id}/retry-processing";
        $originalKey = $upload->original_object_key;
        $originalSha256 = $upload->original_sha256;

        $this->actingAs($member)->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.state', MediaUploadState::Preserved->value);
        $this->actingAs($member)->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.state', MediaUploadState::Preserved->value);

        Queue::assertPushed(GenerateCanonicalMediaUpload::class, function (GenerateCanonicalMediaUpload $job) use (
            $upload,
            $originalSha256,
        ): bool {
            return $job->mediaUploadId === $upload->id && $job->sourceSha256 === $originalSha256;
        });
        Queue::assertPushed(GenerateCanonicalMediaUpload::class, 1);
        $this->assertSame($originalKey, $upload->refresh()->original_object_key);
        $this->assertSame($originalSha256, $upload->original_sha256);
    }

    public function test_variant_recovery_uses_the_frozen_canonical_identity_once(): void
    {
        [$family, $member, $upload] = $this->upload(MediaUploadState::Degraded, 'variant-retry', true);
        $endpoint = "/api/families/{$family->slug}/media-uploads/{$upload->id}/retry-processing";

        $this->actingAs($member)->postJson($endpoint)
            ->assertOk()
            ->assertJsonPath('data.state', MediaUploadState::Processing->value);
        $this->actingAs($member)->postJson($endpoint)->assertOk();

        Queue::assertPushed(GeneratePresentationMediaVariants::class, function (
            GeneratePresentationMediaVariants $job,
        ) use ($upload): bool {
            return $job->mediaUploadId === $upload->id
                && $job->canonicalSha256 === $upload->canonical_sha256
                && $job->processingVersion === 1;
        });
        Queue::assertPushed(GeneratePresentationMediaVariants::class, 1);
        Queue::assertNotPushed(GenerateCanonicalMediaUpload::class);
    }

    public function test_only_the_uploading_owner_administrator_or_member_can_retry(): void
    {
        [$family, $member, $upload] = $this->upload(MediaUploadState::Degraded, 'retry-policy');
        $otherMember = $this->member($family, FamilySpaceRole::Member);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $endpoint = "/api/families/{$family->slug}/media-uploads/{$upload->id}/retry-processing";

        $this->actingAs($otherMember)->postJson($endpoint)->assertForbidden();
        $this->actingAs($contributor)->postJson($endpoint)->assertForbidden();
        $this->actingAs($member)->postJson($endpoint)->assertOk();

        Queue::assertPushed(GenerateCanonicalMediaUpload::class, 1);
    }

    public function test_owner_or_administrator_can_recover_an_orphaned_upload(): void
    {
        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator] as $index => $role) {
            [$family, , $upload] = $this->upload(MediaUploadState::Degraded, "orphan-retry-{$index}");
            $manager = $this->member($family, $role);
            $upload->update(['user_id' => null]);

            $this->actingAs($manager)
                ->postJson("/api/families/{$family->slug}/media-uploads/{$upload->id}/retry-processing")
                ->assertOk()
                ->assertJsonPath('data.state', MediaUploadState::Preserved->value);
        }

        Queue::assertPushed(GenerateCanonicalMediaUpload::class, 2);
    }

    /** @return array{FamilySpace, User, MediaUpload} */
    private function upload(MediaUploadState $state, string $slug, bool $withCanonical = false): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $member = $this->member($family, FamilySpaceRole::Member);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => $state,
            'original_object_key' => "families/{$family->id}/media/original.jpg",
            'original_sha256' => hash('sha256', 'original'),
            'canonical_object_key' => $withCanonical ? "families/{$family->id}/media/canonical.jpg" : null,
            'canonical_mime_type' => $withCanonical ? 'image/jpeg' : null,
            'canonical_sha256' => $withCanonical ? hash('sha256', 'canonical') : null,
        ]);

        return [$family, $member, $upload];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}
