<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Models\DuplicateCandidate;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\User;
use App\Services\ExactDuplicateDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DuplicateReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_owner_and_administrator_can_review_and_dismiss_without_consolidation(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'duplicate-review']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $member = $this->member($family, FamilySpaceRole::Member);
        $first = $this->photo($family, $owner, 'first');
        $second = $this->photo($family, $member, 'second');
        [$low, $high] = $this->pair($first->id, $second->id);
        $candidate = DuplicateCandidate::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'source' => 'perceptual',
            'status' => 'pending',
            'algorithm' => 'dhash-luma-64',
            'processing_version' => 1,
            'score' => 7,
        ]);
        DuplicateCandidate::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'source' => 'member_flagged',
            'status' => 'pending',
        ]);

        $this->actingAs($member)->getJson('/api/families/duplicate-review/duplicate-candidates')
            ->assertForbidden();
        $this->actingAs($owner)->getJson('/api/families/duplicate-review/duplicate-candidates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.source', 'perceptual')
            ->assertJsonPath('data.0.score', 7)
            ->assertJsonPath('data.0.photo.id', $low)
            ->assertJsonPath('data.0.candidate_photo.id', $high);

        $this->actingAs($owner)
            ->postJson("/api/families/duplicate-review/duplicate-candidates/{$candidate->id}/dismiss")
            ->assertOk()
            ->assertJsonPath('data.source', 'perceptual_review');

        $this->assertDatabaseMissing('duplicate_candidates', [
            'photo_id' => $low,
            'candidate_photo_id' => $high,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('duplicate_decisions', [
            'photo_low_id' => $low,
            'photo_high_id' => $high,
            'source' => 'perceptual_review',
            'decided_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_duplicate.candidate_dismissed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_duplicate.decision_recorded']);
        $this->assertSame($first->media_upload_id, $first->refresh()->media_upload_id);
        $this->assertSame($second->media_upload_id, $second->refresh()->media_upload_id);
    }

    public function test_reopening_reuses_the_decision_and_allows_natural_detection_to_surface_the_pair_again(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'duplicate-reopen']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $checksum = hash('sha256', 'same-original');
        $first = $this->photo($family, $owner, 'first', checksum: $checksum);
        $second = $this->photo($family, $owner, 'second', checksum: $checksum);
        app(ExactDuplicateDetector::class)->generateCandidatesFor($first);
        $candidate = DuplicateCandidate::query()->sole();
        $decisionId = $this->actingAs($owner)
            ->postJson("/api/families/duplicate-reopen/duplicate-candidates/{$candidate->id}/dismiss")
            ->assertOk()->json('data.id');

        $this->actingAs($owner)
            ->postJson("/api/families/duplicate-reopen/duplicate-decisions/{$decisionId}/reopen")
            ->assertOk()
            ->assertJsonPath('data.status', 'reopened');
        $this->assertDatabaseCount('duplicate_decisions', 1);
        $this->assertDatabaseHas('duplicate_decisions', ['id' => $decisionId, 'reopened_by' => $owner->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_duplicate.decision_reopened']);

        app(ExactDuplicateDetector::class)->generateCandidatesFor($first);
        $this->assertDatabaseHas('duplicate_candidates', ['id' => $candidate->id, 'status' => 'pending']);
    }

    public function test_member_can_flag_only_two_visible_photos_while_contributor_cannot_flag(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'duplicate-flag']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $member = $this->member($family, FamilySpaceRole::Member);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $first = $this->photo($family, $member, 'first');
        $visible = $this->photo($family, $owner, 'visible');
        $hidden = $this->photo($family, $owner, 'hidden', PhotoVisibility::Private);

        $this->actingAs($member)->postJson("/api/families/duplicate-flag/photos/{$first->id}/duplicate-flags", [
            'candidate_photo_id' => $visible->id,
        ])->assertCreated();
        $this->assertDatabaseHas('duplicate_candidates', ['source' => 'member_flagged', 'status' => 'pending']);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo_duplicate.flagged', 'actor_user_id' => $member->id]);

        $this->actingAs($member)->postJson("/api/families/duplicate-flag/photos/{$first->id}/duplicate-flags", [
            'candidate_photo_id' => $hidden->id,
        ])->assertForbidden();
        $this->actingAs($contributor)->postJson("/api/families/duplicate-flag/photos/{$first->id}/duplicate-flags", [
            'candidate_photo_id' => $visible->id,
        ])->assertForbidden();
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

    private function photo(
        FamilySpace $family,
        User $user,
        string $caption,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
        ?string $checksum = null,
    ): Photo {
        $uploadId = (string) Str::ulid();
        $upload = MediaUpload::factory()->create([
            'id' => $uploadId,
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'state' => MediaUploadState::Ready,
            'original_sha256' => $checksum ?? hash('sha256', $caption.$uploadId),
            'canonical_object_key' => "families/{$family->id}/media/{$uploadId}/canonical.jpg",
            'canonical_sha256' => hash('sha256', 'canonical-'.$uploadId),
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $user->id,
            'caption' => $caption,
            'visibility' => $visibility,
        ]);
    }

    /** @return array{string, string} */
    private function pair(string $first, string $second): array
    {
        return strcmp($first, $second) < 0 ? [$first, $second] : [$second, $first];
    }
}
