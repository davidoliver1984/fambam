<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PersonProposalStatus;
use App\FaceRecognition\FaceIdentityAssignmentManager;
use App\Models\FaceAnalysisRun;
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
