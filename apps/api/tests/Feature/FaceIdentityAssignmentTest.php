<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PersonProposalStatus;
use App\FaceRecognition\FaceIdentityAssignmentManager;
use App\FaceRecognition\FaceIdentitySuppressionManager;
use App\Models\FaceAnalysisRun;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FaceIdentityAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_may_propose_but_only_owner_may_approve_and_ensure_photo_person(): void
    {
        [$family, $owner, $ownerMembership, $photo, $observation] = $this->facePhoto();
        $member = User::factory()->create();
        $memberMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $request = Request::create('/face-identity', 'POST');
        $tenant = app(TenantContext::class);
        $tenant->establish($family, $memberMembership, $member);
        $manager = app(FaceIdentityAssignmentManager::class);

        $assignment = $manager->propose($observation, $person, $member, $request);
        try {
            $manager->approve($assignment, $member, $request);
            $this->fail('A Member unexpectedly approved an authoritative face identity assignment.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $tenant->establish($family, $ownerMembership, $owner);
        $approved = $manager->approve($assignment, $owner, $request);
        $this->assertSame('approved', $approved->status->value);
        $this->assertDatabaseHas('photo_people', [
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'proposal_source' => 'face_identity_assignment',
            'proposed_by' => $member->id,
            'resolved_by' => $owner->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_identity_assignment.proposed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_identity_assignment.approved']);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.person_confirmed']);
        $this->assertDatabaseCount('family_activities', 0);
    }

    public function test_human_selection_supersedes_same_person_machine_suggestion_without_auto_approval(): void
    {
        [$family, $owner, $membership, , $observation] = $this->facePhoto();
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $machine = FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observation->id,
            'person_id' => $person->id,
            'proposal_source' => 'automatic_suggestion',
            'status' => FaceIdentityAssignmentStatus::Pending,
        ]);
        app(TenantContext::class)->establish($family, $membership, $owner);

        $human = app(FaceIdentityAssignmentManager::class)->propose(
            $observation,
            $person,
            $owner,
            Request::create('/face-identity', 'POST'),
        );

        $this->assertSame(FaceIdentityAssignmentStatus::Superseded, $machine->refresh()->status);
        $this->assertSame(FaceIdentityAssignmentStatus::Pending, $human->status);
        $this->assertSame('human', $human->proposal_source);
        $this->assertNull($human->resolved_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_identity_assignment.superseded']);
    }

    public function test_human_may_change_pending_proposal_and_history_is_preserved(): void
    {
        [$family, $owner, $membership, , $observation] = $this->facePhoto();
        $firstPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $secondPerson = Person::factory()->create(['family_space_id' => $family->id]);
        app(TenantContext::class)->establish($family, $membership, $owner);
        $manager = app(FaceIdentityAssignmentManager::class);
        $request = Request::create('/face-identity', 'POST');

        $first = $manager->propose($observation, $firstPerson, $owner, $request);
        $second = $manager->propose($observation, $secondPerson, $owner, $request);

        $this->assertSame(FaceIdentityAssignmentStatus::Superseded, $first->refresh()->status);
        $this->assertSame(FaceIdentityAssignmentStatus::Pending, $second->status);
        $this->assertSame($secondPerson->id, $second->person_id);
        $this->assertDatabaseCount('face_identity_assignments', 2);
    }

    public function test_only_manager_may_correct_approved_identity_and_correction_stays_pending(): void
    {
        [$family, $owner, $ownerMembership, , $observation] = $this->facePhoto();
        $member = User::factory()->create();
        $memberMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $firstPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $correctedPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $tenant = app(TenantContext::class);
        $tenant->establish($family, $ownerMembership, $owner);
        $manager = app(FaceIdentityAssignmentManager::class);
        $request = Request::create('/face-identity', 'POST');
        $approved = $manager->approve(
            $manager->propose($observation, $firstPerson, $owner, $request),
            $owner,
            $request,
        );

        $tenant->establish($family, $memberMembership, $member);
        try {
            $manager->propose($observation, $correctedPerson, $member, $request);
            $this->fail('A Member unexpectedly corrected an approved identity.');
        } catch (AuthorizationException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(FaceIdentityAssignmentStatus::Approved, $approved->refresh()->status);

        $tenant->establish($family, $ownerMembership, $owner);
        $correction = $manager->propose($observation, $correctedPerson, $owner, $request);
        $this->assertSame(FaceIdentityAssignmentStatus::Superseded, $approved->refresh()->status);
        $this->assertSame(FaceIdentityAssignmentStatus::Pending, $correction->status);
        $this->assertSame('human', $correction->proposal_source);
    }

    public function test_photo_detail_exposes_approved_face_boxes_only_to_people_directory_viewers(): void
    {
        [$family, $owner, $ownerMembership, $photo, $observation] = $this->facePhoto();
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Aunt May',
        ]);
        app(TenantContext::class)->establish($family, $ownerMembership, $owner);
        $manager = app(FaceIdentityAssignmentManager::class);
        $assignment = $manager->propose($observation, $person, $owner, Request::create('/face-identity', 'POST'));
        $manager->approve($assignment, $owner, Request::create('/face-identity', 'POST'));
        app(TenantContext::class)->clear();

        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonPath('data.identified_faces.0.id', $assignment->id)
            ->assertJsonPath('data.identified_faces.0.person.id', $person->id)
            ->assertJsonPath('data.identified_faces.0.bounds.x', 0)
            ->assertJsonPath('data.identified_faces.0.bounds.y', 0)
            ->assertJsonPath('data.identified_faces.0.bounds.width', 1)
            ->assertJsonPath('data.identified_faces.0.bounds.height', 1);

        $contributor = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $contributor->id,
            'role' => FamilySpaceRole::Contributor,
            'state' => MembershipState::Active,
        ]);
        $photo->update(['created_by' => $contributor->id]);
        $this->actingAs($contributor)->getJson("/api/families/{$family->slug}/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.identified_faces');
    }

    public function test_approval_reuses_approved_resolves_pending_and_preserves_rejected_history(): void
    {
        [$family, $owner, $membership, $photo, $firstObservation, $run] = $this->facePhoto();
        app(TenantContext::class)->establish($family, $membership, $owner);
        $manager = app(FaceIdentityAssignmentManager::class);
        $request = Request::create('/face-identity', 'POST');

        foreach ([PersonProposalStatus::Approved, PersonProposalStatus::Pending, PersonProposalStatus::Rejected] as $index => $status) {
            $person = Person::factory()->create(['family_space_id' => $family->id]);
            $observation = $index === 0 ? $firstObservation : $this->observation($family, $run, $index);
            $historical = PhotoPerson::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'person_id' => $person->id,
                'proposal_source' => 'human',
                'status' => $status,
                'proposed_by' => $owner->id,
                'resolved_by' => $status === PersonProposalStatus::Pending ? null : $owner->id,
                'resolved_at' => $status === PersonProposalStatus::Pending ? null : now(),
            ]);
            $assignment = $manager->propose($observation, $person, $owner, $request);
            $manager->approve($assignment, $owner, $request);

            $active = PhotoPerson::query()->where('photo_id', $photo->id)->where('person_id', $person->id)
                ->where('status', PersonProposalStatus::Approved)->get();
            $this->assertCount(1, $active);
            if ($status === PersonProposalStatus::Rejected) {
                $this->assertSame(PersonProposalStatus::Rejected, $historical->refresh()->status);
                $this->assertNotSame($historical->id, $active->first()->id);
            } else {
                $this->assertSame($historical->id, $active->first()->id);
            }
        }
    }

    public function test_two_faces_of_one_person_in_one_photo_keep_independent_assignments_and_one_photo_person(): void
    {
        [$family, $owner, $membership, $photo, $first, $run] = $this->facePhoto();
        $second = $this->observation($family, $run, 1);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        app(TenantContext::class)->establish($family, $membership, $owner);
        $manager = app(FaceIdentityAssignmentManager::class);
        $request = Request::create('/face-identity', 'POST');

        $firstAssignment = $manager->propose($first, $person, $owner, $request);
        $secondAssignment = $manager->propose($second, $person, $owner, $request);
        $manager->approve($firstAssignment, $owner, $request);
        $manager->approve($secondAssignment, $owner, $request);

        $this->assertNotSame($firstAssignment->id, $secondAssignment->id);
        $this->assertDatabaseHas('face_identity_assignments', [
            'face_observation_id' => $first->id,
            'person_id' => $person->id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('face_identity_assignments', [
            'face_observation_id' => $second->id,
            'person_id' => $person->id,
            'status' => 'approved',
        ]);
        $this->assertSame(1, PhotoPerson::query()->where('photo_id', $photo->id)
            ->where('person_id', $person->id)->where('status', PersonProposalStatus::Approved)->count());
    }

    public function test_rejection_reuses_durable_suppression_and_reopening_allows_later_proposal(): void
    {
        [$family, $owner, $membership, , $observation] = $this->facePhoto();
        app(TenantContext::class)->establish($family, $membership, $owner);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $request = Request::create('/face-identity', 'POST');
        $assignments = app(FaceIdentityAssignmentManager::class);
        $suppressions = app(FaceIdentitySuppressionManager::class);
        $assignment = $assignments->propose($observation, $person, $owner, $request);

        $suppression = $suppressions->rejectAssignment($assignment, $owner, $request);
        $this->assertSame('rejected', $assignment->refresh()->status->value);
        try {
            $assignments->propose($observation, $person, $owner, $request);
            $this->fail('An active suppression unexpectedly allowed the pair to be proposed again.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }

        $suppressions->reopen($suppression, $owner, $request);
        $later = $assignments->propose($observation, $person, $owner, $request);
        $this->assertSame('pending', $later->status->value);
        $this->assertDatabaseCount('face_identity_suppressions', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_identity_assignment.rejected']);
        $this->assertDatabaseHas('audit_events', ['action' => 'face_identity_suppression.reopened']);
    }

    public function test_member_cannot_reopen_a_face_identity_suppression(): void
    {
        [$family, $owner, $ownerMembership, , $observation] = $this->facePhoto();
        $member = User::factory()->create();
        $memberMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $request = Request::create('/face-identity', 'POST');
        $tenant = app(TenantContext::class);
        $tenant->establish($family, $ownerMembership, $owner);
        $assignment = app(FaceIdentityAssignmentManager::class)->propose($observation, $person, $owner, $request);
        $suppression = app(FaceIdentitySuppressionManager::class)->rejectAssignment($assignment, $owner, $request);
        $tenant->establish($family, $memberMembership, $member);

        $this->expectException(AuthorizationException::class);
        app(FaceIdentitySuppressionManager::class)->reopen($suppression, $member, $request);
    }

    /** @return array{FamilySpace, User, FamilySpaceMembership, Photo, FaceObservation, FaceAnalysisRun} */
    private function facePhoto(): array
    {
        $family = FamilySpace::factory()->create();
        $owner = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
            'state' => MembershipState::Active,
        ]);
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        $identity = config('image-analysis.identity');
        $run = FaceAnalysisRun::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'canonical_sha256' => str_repeat('a', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);

        return [$family, $owner, $membership, $photo, $this->observation($family, $run, 0), $run];
    }

    private function observation(FamilySpace $family, FaceAnalysisRun $run, int $index): FaceObservation
    {
        return FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => $index,
            'bounds_x' => 0,
            'bounds_y' => 0,
            'bounds_width' => 1,
            'bounds_height' => 1,
            'landmarks' => [],
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => pack('g*', 1.0, 0.0, 0.0),
            'embedding_dimension' => 3,
            'embedding_dtype' => 'float32',
        ]);
    }
}
