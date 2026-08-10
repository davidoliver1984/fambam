<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaUploadBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_status_reports_independent_per_file_progress_without_private_metadata(): void
    {
        [$family, $member] = $this->familyMember(FamilySpaceRole::Member, 'batch-status');
        $batchId = (string) Str::ulid();
        $ready = $this->batchUpload($family, $member, $batchId, 'ready.jpg', MediaUploadState::Ready);
        $this->batchUpload($family, $member, $batchId, 'processing.heic', MediaUploadState::Processing);
        $this->batchUpload(
            $family,
            $member,
            $batchId,
            'rejected.tiff',
            MediaUploadState::Quarantined,
            'malware_detected',
        );

        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/media-upload-batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.batch_id', $batchId)
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.counts.ready', 1)
            ->assertJsonPath('data.counts.processing', 1)
            ->assertJsonPath('data.counts.quarantined', 1)
            ->assertJsonMissingPath('data.items.2.rejection_reason')
            ->assertJsonMissingPath('data.items.0.gps_latitude')
            ->assertJsonMissingPath('data.items.0.original_sha256');

        $this->assertSame($batchId, $ready->upload_batch_id);
    }

    public function test_owner_and_administrator_can_see_their_own_rejection_reason(): void
    {
        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator] as $index => $role) {
            [$family, $user] = $this->familyMember($role, "batch-rejection-{$index}");
            $batchId = (string) Str::ulid();
            $this->batchUpload(
                $family,
                $user,
                $batchId,
                'rejected.jpg',
                MediaUploadState::Quarantined,
                'malware_detected',
            );

            $this->actingAs($user)
                ->getJson("/api/families/{$family->slug}/media-upload-batches/{$batchId}")
                ->assertOk()
                ->assertJsonPath('data.active', false)
                ->assertJsonPath('data.items.0.rejection_reason', 'malware_detected');
        }
    }

    public function test_batch_status_is_user_scoped_and_denied_to_deferred_roles(): void
    {
        [$family, $member] = $this->familyMember(FamilySpaceRole::Member, 'private-batch');
        $otherMember = $this->addMember($family, FamilySpaceRole::Member);
        $contributor = $this->addMember($family, FamilySpaceRole::Contributor);
        $batchId = (string) Str::ulid();
        $this->batchUpload($family, $member, $batchId, 'private.jpg', MediaUploadState::Ready);
        $endpoint = "/api/families/{$family->slug}/media-upload-batches/{$batchId}";

        $this->getJson($endpoint)->assertUnauthorized();
        $this->actingAs($otherMember)->getJson($endpoint)->assertNotFound();
        $this->actingAs($contributor)->getJson($endpoint)->assertForbidden();
    }

    public function test_owner_and_administrator_can_monitor_orphaned_batches(): void
    {
        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator] as $index => $role) {
            [$family, $manager] = $this->familyMember($role, "orphan-batch-{$index}");
            $formerUploader = $this->addMember($family, FamilySpaceRole::Member);
            $batchId = (string) Str::ulid();
            $upload = $this->batchUpload(
                $family,
                $formerUploader,
                $batchId,
                'orphaned.jpg',
                MediaUploadState::Degraded,
            );
            $upload->update(['user_id' => null]);

            $this->actingAs($manager)
                ->getJson("/api/families/{$family->slug}/media-upload-batches/{$batchId}")
                ->assertOk()
                ->assertJsonPath('data.active', true)
                ->assertJsonPath('data.items.0.id', $upload->id);
        }
    }

    private function batchUpload(
        FamilySpace $family,
        User $user,
        string $batchId,
        string $filename,
        MediaUploadState $state,
        ?string $rejectionReason = null,
    ): MediaUpload {
        return MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'upload_batch_id' => $batchId,
            'client_filename' => $filename,
            'state' => $state,
            'rejection_reason' => $rejectionReason,
        ]);
    }

    /** @return array{FamilySpace, User} */
    private function familyMember(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->addMember($family, $role)];
    }

    private function addMember(FamilySpace $family, FamilySpaceRole $role): User
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
