<?php

namespace Tests\Feature;

use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\AuditEvent;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecognitionConsentTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_consent_change_withdraws_only_pending_assignments_as_one_audited_action(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'recognition-consent');
        $member = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $this->assertFalse($person->recognition_allowed);

        $this->actingAs($member)->putJson(
            "/api/families/recognition-consent/people/{$person->id}/recognition-consent",
            ['recognition_allowed' => true],
        )->assertForbidden();
        $this->actingAs($owner)->putJson(
            "/api/families/recognition-consent/people/{$person->id}/recognition-consent",
            ['recognition_allowed' => true],
        )->assertOk()->assertJsonPath('data.recognition_allowed', true);

        [$photo, $first, $second] = $this->facePhoto($family, $owner);
        $pending = FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $first->id,
            'person_id' => $person->id,
            'proposal_source' => 'automatic_suggestion',
            'status' => 'pending',
        ]);
        $approved = FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $second->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);
        $photoPerson = PhotoPerson::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'proposal_source' => 'face_identity_assignment',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);

        $this->actingAs($owner)->putJson(
            "/api/families/recognition-consent/people/{$person->id}/recognition-consent",
            ['recognition_allowed' => false],
        )->assertOk()->assertJsonPath('data.recognition_allowed', false);

        $this->assertSame('withdrawn', $pending->refresh()->status->value);
        $this->assertSame($owner->id, $pending->resolved_by);
        $this->assertSame('approved', $approved->refresh()->status->value);
        $this->assertSame('approved', $photoPerson->refresh()->status->value);
        $this->assertDatabaseCount('face_observations', 2);
        $this->assertDatabaseCount('face_identity_suppressions', 0);
        $this->assertDatabaseCount('audit_events', 2);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'person.recognition_consent_changed',
            'subject_id' => $person->id,
        ]);
        $disableAudit = AuditEvent::query()->where('action', 'person.recognition_consent_changed')
            ->orderByDesc('id')->firstOrFail();
        $this->assertSame(false, $disableAudit->metadata['recognition_allowed']);
        $this->assertSame(1, $disableAudit->metadata['withdrawn_assignment_count']);

        $this->actingAs($owner)->putJson(
            "/api/families/recognition-consent/people/{$person->id}/recognition-consent",
            ['recognition_allowed' => true],
        )->assertOk();
        $this->assertSame('withdrawn', $pending->refresh()->status->value);
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'state' => MembershipState::Active,
        ]);

        return [$family, $user];
    }

    /** @return array{Photo, FaceObservation, FaceObservation} */
    private function facePhoto(FamilySpace $family, User $owner): array
    {
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
            'canonical_sha256' => str_repeat('c', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);

        return [$photo, $this->observation($family, $run, 0), $this->observation($family, $run, 1)];
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
