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

class PersonRecognitionSummaryHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_person_summary_counts_distinct_authorised_photos_from_current_approved_identities(): void
    {
        [$family, $owner] = $this->family('person-recognition');
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $otherPerson = Person::factory()->create(['family_space_id' => $family->id]);

        $first = $this->photo($family, $owner);
        $firstRun = $this->analysisRun($first);
        $this->assignment($family, $this->observation($family, $firstRun, 0), $person, FaceIdentityAssignmentStatus::Approved, $viewer, $owner);
        $this->assignment($family, $this->observation($family, $firstRun, 1), $person, FaceIdentityAssignmentStatus::Approved, $owner, $owner);

        $second = $this->photo($family, $owner);
        $this->assignment(
            $family,
            $this->observation($family, $this->analysisRun($second), 0),
            $person,
            FaceIdentityAssignmentStatus::Approved,
            null,
            $viewer,
            'automatic_suggestion',
        );

        foreach ([
            [FaceIdentityAssignmentStatus::Pending, 'automatic_suggestion'],
            [FaceIdentityAssignmentStatus::Pending, 'human'],
            [FaceIdentityAssignmentStatus::Rejected, 'human'],
        ] as [$status, $source]) {
            $photo = $this->photo($family, $owner);
            $this->assignment($family, $this->observation($family, $this->analysisRun($photo), 0), $person, $status, $viewer, null, $source);
        }

        $leftPhoto = $this->photo($family, $owner);
        $left = $this->observation($family, $this->analysisRun($leftPhoto), 0);
        FaceObservationReview::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $left->id,
            'disposition' => FaceObservationReviewDisposition::LeftUnidentified,
            'reviewed_by' => $viewer->id,
            'reviewed_at' => now(),
        ]);

        $correctedPhoto = $this->photo($family, $owner);
        $corrected = $this->observation($family, $this->analysisRun($correctedPhoto), 0);
        $this->assignment($family, $corrected, $person, FaceIdentityAssignmentStatus::Superseded, $viewer, $owner);
        $this->assignment($family, $corrected, $otherPerson, FaceIdentityAssignmentStatus::Approved, $owner, $owner);

        $stalePhoto = $this->photo($family, $owner);
        $this->assignment(
            $family,
            $this->observation($family, $this->analysisRun($stalePhoto, str_repeat('b', 64)), 0),
            $person,
            FaceIdentityAssignmentStatus::Approved,
            $viewer,
            $owner,
        );

        $hidden = $this->photo($family, $owner, PhotoVisibility::Private);
        $this->assignment($family, $this->observation($family, $this->analysisRun($hidden), 0), $person, FaceIdentityAssignmentStatus::Approved, $viewer, $owner);

        $deleted = $this->photo($family, $owner);
        $this->assignment($family, $this->observation($family, $this->analysisRun($deleted), 0), $person, FaceIdentityAssignmentStatus::Approved, $viewer, $owner);
        $deleted->delete();

        $inactive = $this->photo($family, $owner);
        $this->assignment($family, $this->observation($family, $this->analysisRun($inactive), 0), $person, FaceIdentityAssignmentStatus::Approved, $viewer, $owner);
        $inactive->mediaUpload()->update(['state' => MediaUploadState::Degraded]);

        [$otherFamily, $otherOwner] = $this->family('other-recognition');
        $otherFamilyPerson = Person::factory()->create(['family_space_id' => $otherFamily->id]);
        $otherPhoto = $this->photo($otherFamily, $otherOwner);
        $this->assignment(
            $otherFamily,
            $this->observation($otherFamily, $this->analysisRun($otherPhoto), 0),
            $otherFamilyPerson,
            FaceIdentityAssignmentStatus::Approved,
            $viewer,
            $otherOwner,
        );

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people/{$person->id}")
            ->assertOk();
        $response
            ->assertJsonPath('data.recognition_summary.recognised_photo_count', 2)
            ->assertJsonPath('data.recognition_summary.viewer_identification_count', 2)
            ->assertJsonPath('data.recognition_summary.review_destination.path', "/families/{$family->slug}/photos/review-people")
            ->assertJsonPath('data.recognition_summary.review_destination.filter.person_id', $person->id);
        $recognitionQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'face_identity_assignments')
                && str_contains($query['query'], 'count('),
        );
        DB::disableQueryLog();

        $this->assertCount(2, $recognitionQueries);
        $this->assertSame($person->id, $response->json('data.recognition_summary.review_destination.filter.person_id'));

        $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/face-review?person_id={$person->id}")
            ->assertOk()
            ->assertJsonPath('data.scope.person_id', $person->id)
            ->assertJsonPath('data.scope.photo_id', null)
            ->assertJsonPath('data.scope.upload_batch_id', null)
            ->assertJsonPath('data.summary.total_photos', 2)
            ->assertJsonCount(2, 'data.photos');

        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/people/{$person->id}")
            ->assertForbidden();
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->member($family, FamilySpaceRole::Owner)];
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
        User $creator,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/canonical/".Str::ulid().'.webp',
            'canonical_mime_type' => 'image/webp',
            'canonical_sha256' => str_repeat('a', 64),
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
        ]);
    }

    private function analysisRun(Photo $photo, ?string $configHash = null): FaceAnalysisRun
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
            'config_hash' => $configHash ?? $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);
    }

    private function observation(FamilySpace $family, FaceAnalysisRun $run, int $index): FaceObservation
    {
        return FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => $index,
            'bounds_x' => 10,
            'bounds_y' => 10,
            'bounds_width' => 20,
            'bounds_height' => 20,
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
        ?User $proposer,
        ?User $resolver,
        string $source = 'human',
    ): FaceIdentityAssignment {
        return FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observation->id,
            'person_id' => $person->id,
            'proposal_source' => $source,
            'status' => $status,
            'proposed_by' => $proposer?->id,
            'resolved_by' => $resolver?->id,
            'resolved_at' => $resolver === null ? null : now(),
        ]);
    }
}
