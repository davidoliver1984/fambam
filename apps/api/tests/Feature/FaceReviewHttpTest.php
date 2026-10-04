<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FaceObservationReviewDisposition;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Models\FaceAnalysisRun;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceIdentitySuppression;
use App\Models\FaceObservation;
use App\Models\FaceObservationReview;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FaceReviewHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_model_reports_analysis_review_progress_and_deterministic_ordering(): void
    {
        [$family, $owner] = $this->familyMember(FamilySpaceRole::Owner, 'face-review');
        $batchId = (string) Str::ulid();
        $pending = $this->photo($family, $owner, $batchId, 'Pending', now()->subMinutes(5));
        $processing = $this->photo($family, $owner, $batchId, 'Processing', now()->subMinutes(4));
        $withFaces = $this->photo($family, $owner, $batchId, 'Faces', now()->subMinutes(3));
        $zeroFaces = $this->photo($family, $owner, $batchId, 'None', now()->subMinutes(2));
        $failed = $this->photo($family, $owner, $batchId, 'Failed', now()->subMinute());
        $this->analysisRun($processing, FaceAnalysisRunStatus::Processing);
        $run = $this->analysisRun($withFaces, FaceAnalysisRunStatus::Succeeded);
        $this->analysisRun($zeroFaces, FaceAnalysisRunStatus::Succeeded);
        $this->analysisRun($failed, FaceAnalysisRunStatus::Failed);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $left = $this->observation($family, $run, 3);
        $approved = $this->observation($family, $run, 2);
        $humanPending = $this->observation($family, $run, 1);
        $automaticPending = $this->observation($family, $run, 0);
        $rejected = $this->observation($family, $run, 4);
        $withdrawn = $this->observation($family, $run, 5);
        $superseded = $this->observation($family, $run, 6);
        FaceObservationReview::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $left->id,
            'disposition' => FaceObservationReviewDisposition::LeftUnidentified,
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
        ]);
        $this->assignment($family, $approved, $person, FaceIdentityAssignmentStatus::Approved, 'automatic_suggestion');
        $this->assignment($family, $humanPending, $person, FaceIdentityAssignmentStatus::Pending, 'human');
        $this->assignment($family, $automaticPending, $person, FaceIdentityAssignmentStatus::Pending, 'automatic_suggestion');
        $this->assignment($family, $rejected, $person, FaceIdentityAssignmentStatus::Rejected, 'human');
        $this->assignment($family, $withdrawn, $person, FaceIdentityAssignmentStatus::Withdrawn, 'automatic_suggestion');
        $this->assignment($family, $superseded, $person, FaceIdentityAssignmentStatus::Superseded, 'human');

        $response = $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/face-review?upload_batch_id={$batchId}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_photos', 5)
            ->assertJsonPath('data.summary.analysis.pending', 1)
            ->assertJsonPath('data.summary.analysis.processing', 1)
            ->assertJsonPath('data.summary.analysis.succeeded', 2)
            ->assertJsonPath('data.summary.analysis.failed', 1)
            ->assertJsonPath('data.summary.analysis.succeeded_with_zero_faces', 1)
            ->assertJsonPath('data.summary.total_faces', 7)
            ->assertJsonPath('data.summary.reviewed_count', 3)
            ->assertJsonPath('data.summary.remaining_count', 4)
            ->assertJsonPath('data.summary.current_photo_id', $withFaces->id)
            ->assertJsonPath('data.photos.2.display_label', 'Faces')
            ->assertJsonPath('data.photos.2.media.canonical_width', 1200)
            ->assertJsonPath('data.photos.2.media.canonical_height', 800)
            ->assertJsonPath('data.photos.2.observations.0.id', $automaticPending->id)
            ->assertJsonPath('data.photos.2.analysis.review_state', 'succeeded_with_unresolved_faces')
            ->assertJsonPath('data.photos.2.observations.0.review_state', 'automatic_suggestion')
            ->assertJsonPath('data.photos.2.observations.0.reviewed', false)
            ->assertJsonPath('data.photos.2.observations.0.suggested_people.0.id', $person->id)
            ->assertJsonPath('data.photos.2.observations.1.review_state', 'human_proposal')
            ->assertJsonPath('data.photos.2.observations.1.current_proposal.person.id', $person->id)
            ->assertJsonPath('data.photos.2.observations.2.review_state', 'approved_identity')
            ->assertJsonPath('data.photos.2.observations.2.current_identity.person.id', $person->id)
            ->assertJsonPath('data.photos.2.observations.2.permissions.can_change', true)
            ->assertJsonPath('data.photos.2.observations.3.review_state', 'left_unidentified')
            ->assertJsonPath('data.photos.2.observations.4.review_state', 'unreviewed')
            ->assertJsonPath('data.photos.2.observations.5.review_state', 'unreviewed')
            ->assertJsonPath('data.photos.2.observations.6.review_state', 'unreviewed');

        $this->assertSame([
            $pending->id, $processing->id, $withFaces->id, $zeroFaces->id, $failed->id,
        ], array_column($response->json('data.photos'), 'photo_id'));
    }

    public function test_leave_unidentified_is_durable_withdraws_a_suggestion_and_creates_no_identity_or_suppression(): void
    {
        [$family, $member] = $this->familyMember(FamilySpaceRole::Member, 'leave-unidentified');
        $photo = $this->photo($family, $member, (string) Str::ulid(), 'Portrait', now());
        $run = $this->analysisRun($photo, FaceAnalysisRunStatus::Succeeded);
        $observation = $this->observation($family, $run, 0);
        $unresolved = $this->observation($family, $run, 1);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $assignment = $this->assignment(
            $family,
            $observation,
            $person,
            FaceIdentityAssignmentStatus::Pending,
            'automatic_suggestion',
        );
        $peopleBefore = Person::query()->count();

        $this->actingAs($member)->putJson(
            "/api/families/{$family->slug}/face-observations/{$observation->id}/review/left-unidentified",
        )->assertOk()
            ->assertJsonPath('data.observation_id', $observation->id)
            ->assertJsonPath('data.review_state', 'left_unidentified');

        $this->assertDatabaseHas('face_observation_reviews', [
            'face_observation_id' => $observation->id,
            'disposition' => 'left_unidentified',
        ]);
        $this->assertSame(FaceIdentityAssignmentStatus::Withdrawn, $assignment->refresh()->status);
        $this->assertSame($peopleBefore, Person::query()->count());
        $this->assertSame(0, FaceIdentitySuppression::query()->count());
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/face-review?photo_id={$photo->id}")
            ->assertOk()
            ->assertJsonPath('data.summary.reviewed_count', 1)
            ->assertJsonPath('data.summary.remaining_count', 1)
            ->assertJsonPath('data.photos.0.observations.1.id', $unresolved->id)
            ->assertJsonPath('data.photos.0.observations.1.review_state', 'unreviewed');
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/face-review?photo_id={$photo->id}")
            ->assertOk()->assertJsonPath('data.summary.remaining_count', 1);
        $this->assertDatabaseCount('face_observation_reviews', 1);

        $this->actingAs($member)->postJson(
            "/api/families/{$family->slug}/face-observations/{$observation->id}/identity-assignments",
            ['person_id' => $person->id],
        )->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.proposal_source', 'human');
        $this->assertDatabaseMissing('face_observation_reviews', [
            'face_observation_id' => $observation->id,
        ]);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/face-review?photo_id={$photo->id}")
            ->assertOk()
            ->assertJsonPath('data.photos.0.observations.0.review_state', 'human_proposal');

        $human = FaceIdentityAssignment::query()
            ->where('face_observation_id', $observation->id)
            ->where('status', FaceIdentityAssignmentStatus::Pending)
            ->firstOrFail();
        $this->actingAs($member)->putJson(
            "/api/families/{$family->slug}/face-observations/{$observation->id}/review/left-unidentified",
        )->assertOk();
        $this->assertSame(FaceIdentityAssignmentStatus::Superseded, $human->refresh()->status);
    }

    public function test_hidden_cross_family_photos_and_deferred_roles_are_excluded(): void
    {
        [$family, $owner] = $this->familyMember(FamilySpaceRole::Owner, 'review-auth');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $visible = $this->photo($family, $owner, null, 'Visible', now()->subMinute());
        $hidden = $this->photo($family, $owner, null, 'Hidden', now(), PhotoVisibility::Private);
        $this->observation($family, $this->analysisRun($visible, FaceAnalysisRunStatus::Succeeded), 0);
        $this->observation($family, $this->analysisRun($hidden, FaceAnalysisRunStatus::Succeeded), 0);
        [$otherFamily, $otherOwner] = $this->familyMember(FamilySpaceRole::Owner, 'other-review');
        $other = $this->photo($otherFamily, $otherOwner, null, 'Other', now());
        $this->observation($otherFamily, $this->analysisRun($other, FaceAnalysisRunStatus::Succeeded), 0);

        $this->actingAs($member)->getJson("/api/families/{$family->slug}/face-review")
            ->assertOk()
            ->assertJsonPath('data.summary.total_photos', 1)
            ->assertJsonPath('data.photos.0.photo_id', $visible->id)
            ->assertJsonMissing(['photo_id' => $hidden->id])
            ->assertJsonMissing(['photo_id' => $other->id]);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/face-review?photo_id={$hidden->id}")
            ->assertNotFound();

        $contributor = $this->addMember($family, FamilySpaceRole::Contributor);
        $this->actingAs($contributor)->getJson("/api/families/{$family->slug}/face-review")
            ->assertForbidden();
        $guest = $this->addMember($family, FamilySpaceRole::Guest);
        $this->actingAs($guest)->getJson("/api/families/{$family->slug}/face-review")
            ->assertForbidden();
        $administrator = $this->addMember($family, FamilySpaceRole::Administrator);
        $this->actingAs($administrator)->getJson("/api/families/{$family->slug}/face-review")
            ->assertOk()
            ->assertJsonPath('data.photos.0.observations.0.permissions.can_assign', true);
    }

    public function test_photo_scope_never_includes_or_navigates_to_another_photo(): void
    {
        [$family, $owner] = $this->familyMember(FamilySpaceRole::Owner, 'single-photo-review');
        $requested = $this->photo($family, $owner, null, 'Requested', now()->subMinute());
        $other = $this->photo($family, $owner, null, 'Other', now());
        $this->observation($family, $this->analysisRun($requested, FaceAnalysisRunStatus::Succeeded), 0);
        $this->observation($family, $this->analysisRun($other, FaceAnalysisRunStatus::Succeeded), 0);

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/face-review?photo_id={$requested->id}")
            ->assertOk()
            ->assertJsonPath('data.scope.photo_id', $requested->id)
            ->assertJsonPath('data.scope.upload_batch_id', null)
            ->assertJsonPath('data.summary.total_photos', 1)
            ->assertJsonPath('data.summary.current_photo_id', $requested->id)
            ->assertJsonPath('data.summary.next_photo_id', null)
            ->assertJsonPath('data.pagination.has_more', false)
            ->assertJsonCount(1, 'data.photos')
            ->assertJsonPath('data.photos.0.photo_id', $requested->id)
            ->assertJsonMissing(['photo_id' => $other->id]);
    }

    public function test_upload_batch_handoff_only_reports_real_unresolved_faces(): void
    {
        [$family, $owner] = $this->familyMember(FamilySpaceRole::Owner, 'batch-face-review');
        $batchId = (string) Str::ulid();
        $photo = $this->photo($family, $owner, $batchId, 'Batch photo', now());
        $this->observation($family, $this->analysisRun($photo, FaceAnalysisRunStatus::Succeeded), 0);

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/media-upload-batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.face_review.analysis_pending', false)
            ->assertJsonPath('data.face_review.has_reviewable_faces', true)
            ->assertJsonPath('data.face_review.reviewable_face_count', 1)
            ->assertJsonPath('data.face_review.affected_photo_count', 1)
            ->assertJsonPath('data.face_review.zero_detected_faces', false);
    }

    public function test_read_query_count_stays_bounded_as_photos_and_faces_grow(): void
    {
        [$family, $owner] = $this->familyMember(FamilySpaceRole::Owner, 'bounded-face-review');
        foreach (range(1, 12) as $photoIndex) {
            $photo = $this->photo($family, $owner, null, "Photo {$photoIndex}", now()->addSeconds($photoIndex));
            $run = $this->analysisRun($photo, FaceAnalysisRunStatus::Succeeded);
            foreach (range(0, 3) as $faceIndex) {
                $this->observation($family, $run, $faceIndex);
            }
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/face-review?limit=100")
            ->assertOk()
            ->assertJsonPath('data.summary.total_faces', 48);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(20, $queryCount);
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

    private function photo(
        FamilySpace $family,
        User $creator,
        ?string $batchId,
        string $caption,
        mixed $createdAt,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => MediaUploadState::Ready,
            'upload_batch_id' => $batchId,
            'canonical_object_key' => "families/{$family->id}/canonical/".Str::ulid().'.webp',
            'canonical_mime_type' => 'image/webp',
            'canonical_sha256' => str_repeat('a', 64),
            'pixel_width' => 1200,
            'pixel_height' => 800,
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
            'caption' => $caption,
            'created_at' => $createdAt,
        ]);
    }

    private function analysisRun(Photo $photo, FaceAnalysisRunStatus $status): FaceAnalysisRun
    {
        $identity = config('image-analysis.identity');

        return FaceAnalysisRun::query()->create([
            'family_space_id' => $photo->family_space_id,
            'media_upload_id' => $photo->media_upload_id,
            'canonical_sha256' => str_repeat('a', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => $status,
            'attempt_count' => $status === FaceAnalysisRunStatus::Pending ? 0 : 1,
            'succeeded_at' => $status === FaceAnalysisRunStatus::Succeeded ? now() : null,
            'failed_at' => $status === FaceAnalysisRunStatus::Failed ? now() : null,
        ]);
    }

    private function observation(FamilySpace $family, FaceAnalysisRun $run, int $index): FaceObservation
    {
        return FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => $index,
            'bounds_x' => 100 + $index,
            'bounds_y' => 120 + $index,
            'bounds_width' => 200,
            'bounds_height' => 220,
            'landmarks' => [],
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => pack('g*', 1.0, 0.0, 0.0),
            'embedding_dimension' => 3,
            'embedding_dtype' => 'float32',
        ]);
    }

    private function assignment(
        FamilySpace $family,
        FaceObservation $observation,
        Person $person,
        FaceIdentityAssignmentStatus $status,
        string $source,
    ): FaceIdentityAssignment {
        return FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observation->id,
            'person_id' => $person->id,
            'proposal_source' => $source,
            'status' => $status,
        ]);
    }
}
