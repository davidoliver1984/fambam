<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Models\FamilyCircle;
use App\Models\FamilyCirclePerson;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonMerge;
use App\Models\PersonMergeProposal;
use App\Models\PersonRelationship;
use App\Models\RelationshipProposal;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_merge_keeps_a_tombstone_and_atomically_reconciles_phase_four_references(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'merge-family');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');
        $relative = $this->person($family, 'Relative');
        $friend = $this->person($family, 'Friend');
        $this->relationship($family, $absorbed, $relative, 'parent_of', $owner);
        $survivorSibling = $this->relationship($family, $survivor, $friend, 'sibling_of', $owner);
        $absorbedSibling = $this->relationship($family, $absorbed, $friend, 'sibling_of', $owner);
        $proposal = RelationshipProposal::query()->create([
            'family_space_id' => $family->id,
            'action' => 'dispute',
            'relationship_id' => $absorbedSibling->id,
            'subject_person_id' => $absorbed->id,
            'related_person_id' => $friend->id,
            'type' => 'sibling_of',
            'status' => 'pending',
            'proposed_by' => $owner->id,
        ]);
        $this->relationship($family, $absorbed, $survivor, 'guardian_of', $owner);
        $circle = FamilyCircle::query()->create([
            'family_space_id' => $family->id,
            'name' => 'Wedding Group',
            'created_by' => $owner->id,
        ]);
        $this->circleMembership($family, $circle, $survivor, $owner);
        $this->circleMembership($family, $circle, $absorbed, $owner);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $absorbed->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);

        $mergeId = $this->actingAs($owner)
            ->postJson("/api/families/merge-family/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.survivor.id', $survivor->id)
            ->assertJsonPath('data.absorbed.id', $absorbed->id)
            ->json('data.id');

        $this->assertSoftDeleted('people', ['id' => $absorbed->id]);
        $this->assertDatabaseHas('person_merges', ['id' => $mergeId, 'status' => 'active']);
        $this->assertSame(2, PersonRelationship::query()->count());
        $this->assertDatabaseHas('person_relationships', [
            'subject_person_id' => $survivor->id,
            'related_person_id' => $relative->id,
            'type' => 'parent_of',
        ]);
        $this->assertDatabaseMissing('person_relationships', ['subject_person_id' => $absorbed->id]);
        $this->assertDatabaseHas('relationship_proposals', [
            'id' => $proposal->id,
            'relationship_id' => $survivorSibling->id,
            'subject_person_id' => $survivor->id,
        ]);
        $this->assertDatabaseCount('family_circle_people', 1);
        $this->assertDatabaseHas('family_circle_people', ['person_id' => $survivor->id]);
        $this->assertDatabaseHas('person_account_links', [
            'person_id' => $survivor->id,
            'user_id' => $owner->id,
        ]);
        $this->actingAs($owner)
            ->getJson("/api/families/merge-family/people/{$absorbed->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $survivor->id)
            ->assertJsonPath('data.redirected_from_person_id', $absorbed->id);
        $this->actingAs($owner)->getJson('/api/families/merge-family/people')
            ->assertOk()->assertJsonCount(3, 'data');
        $this->assertDatabaseHas('audit_events', ['action' => 'person.merged', 'subject_id' => $mergeId]);
        $this->assertIsArray(PersonMerge::query()->findOrFail($mergeId)->provenance['before']);
    }

    public function test_member_proposes_a_duplicate_and_owner_approves_or_rejects(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'proposal-merge');
        $member = $this->addRole($family, FamilySpaceRole::Member);
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');

        $this->actingAs($member)
            ->postJson("/api/families/proposal-merge/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertForbidden();
        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/proposal-merge/people/{$absorbed->id}/merge-proposals", [
                'survivor_person_id' => $survivor->id,
                'context' => 'The same person appears twice.',
            ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->actingAs($member)
            ->getJson("/api/families/proposal-merge/people/{$absorbed->id}/merge-proposals")
            ->assertForbidden();
        $this->actingAs($owner)
            ->postJson("/api/families/proposal-merge/people/{$absorbed->id}/merge-proposals/{$proposalId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertSoftDeleted('people', ['id' => $absorbed->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.duplicate_proposed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.duplicate_proposal_approved']);

        $secondDuplicate = $this->person($family, 'Another duplicate');
        $rejectedId = $this->actingAs($member)
            ->postJson("/api/families/proposal-merge/people/{$secondDuplicate->id}/merge-proposals", [
                'survivor_person_id' => $survivor->id,
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/proposal-merge/people/{$secondDuplicate->id}/merge-proposals/{$rejectedId}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertNotSoftDeleted('people', ['id' => $secondDuplicate->id]);
    }

    public function test_relationship_conflict_aborts_the_entire_merge(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'merge-conflict');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');
        $relative = $this->person($family, 'Relative');
        $this->relationship($family, $survivor, $relative, 'partner_of', $owner);
        $this->relationship($family, $absorbed, $relative, 'parent_of', $owner);

        $this->actingAs($owner)
            ->postJson("/api/families/merge-conflict/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertUnprocessable()->assertJsonValidationErrors('relationship');

        $this->assertNotSoftDeleted('people', ['id' => $absorbed->id]);
        $this->assertDatabaseCount('person_merges', 0);
        $this->assertDatabaseCount('person_relationships', 2);
    }

    public function test_two_account_links_require_an_explicit_merge_resolution(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'merge-links');
        $member = $this->addRole($family, FamilySpaceRole::Member);
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');
        $this->link($family, $survivor, $owner, $owner);
        $this->link($family, $absorbed, $member, $owner);

        $url = "/api/families/merge-links/people/{$absorbed->id}/merge";
        $this->actingAs($owner)->postJson($url, [
            'survivor_person_id' => $survivor->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('person_merge');
        $this->assertDatabaseCount('person_account_links', 2);

        $this->actingAs($owner)->postJson($url, [
            'survivor_person_id' => $survivor->id,
            'account_link_resolution' => 'keep_absorbed',
        ])->assertCreated();
        $this->assertDatabaseCount('person_account_links', 1);
        $this->assertDatabaseHas('person_account_links', [
            'person_id' => $survivor->id,
            'user_id' => $member->id,
        ]);
    }

    public function test_safe_reversal_restores_the_absorbed_person_and_exact_owned_references(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'reverse-merge');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');
        $relative = $this->person($family, 'Relative');
        $relationship = $this->relationship($family, $absorbed, $relative, 'guardian_of', $owner);
        $proposal = RelationshipProposal::query()->create([
            'family_space_id' => $family->id,
            'action' => 'dispute',
            'relationship_id' => $relationship->id,
            'subject_person_id' => $absorbed->id,
            'related_person_id' => $relative->id,
            'type' => 'guardian_of',
            'status' => 'pending',
            'proposed_by' => $owner->id,
        ]);
        $circle = FamilyCircle::query()->create([
            'family_space_id' => $family->id, 'name' => 'Friends', 'created_by' => $owner->id,
        ]);
        $membership = $this->circleMembership($family, $circle, $absorbed, $owner);
        $link = $this->link($family, $absorbed, $owner, $owner);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-link-family']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $otherFamily->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);
        $otherFamilyLink = $this->link(
            $otherFamily,
            $this->person($otherFamily, 'Other family identity'),
            $owner,
            $owner,
        );

        $mergeId = $this->actingAs($owner)
            ->postJson("/api/families/reverse-merge/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/reverse-merge/person-merges/{$mergeId}/reverse")
            ->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->assertNotSoftDeleted('people', ['id' => $absorbed->id]);
        $this->assertDatabaseHas('person_relationships', [
            'id' => $relationship->id,
            'subject_person_id' => $absorbed->id,
        ]);
        $this->assertDatabaseHas('relationship_proposals', [
            'id' => $proposal->id,
            'relationship_id' => $relationship->id,
            'subject_person_id' => $absorbed->id,
            'status' => 'pending',
        ]);
        $this->assertDatabaseHas('family_circle_people', [
            'id' => $membership->id,
            'person_id' => $absorbed->id,
        ]);
        $this->assertDatabaseHas('person_account_links', [
            'id' => $link->id,
            'person_id' => $absorbed->id,
        ]);
        $this->assertDatabaseHas('person_account_links', ['id' => $otherFamilyLink->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.merge_reversed']);
    }

    public function test_reversal_refuses_to_guess_after_survivor_state_changes(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'unsafe-reverse');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Duplicate');
        $relative = $this->person($family, 'New relative');
        $mergeId = $this->actingAs($owner)
            ->postJson("/api/families/unsafe-reverse/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson("/api/families/unsafe-reverse/people/{$survivor->id}/relationships", [
            'related_person_id' => $relative->id,
            'type' => 'close_family_friend_of',
        ])->assertCreated();

        $this->actingAs($owner)
            ->postJson("/api/families/unsafe-reverse/person-merges/{$mergeId}/reverse")
            ->assertUnprocessable()->assertJsonValidationErrors('person_merge');
        $this->assertDatabaseHas('person_merges', [
            'id' => $mergeId,
            'status' => 'manual_correction_required',
        ]);
        $this->assertSoftDeleted('people', ['id' => $absorbed->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.merge_manual_correction_required']);
    }

    public function test_merge_identifiers_fail_closed_across_family_spaces(): void
    {
        [$first, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'first-merge');
        [$second] = $this->familyWithRole(FamilySpaceRole::Owner, 'second-merge');
        $absorbed = $this->person($first, 'Local');
        $foreign = $this->person($second, 'Foreign');

        $this->actingAs($owner)
            ->postJson("/api/families/first-merge/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $foreign->id,
            ])->assertNotFound();
        $this->assertNotSoftDeleted('people', ['id' => $absorbed->id]);
    }

    public function test_database_rejects_a_second_active_merge_for_an_absorbed_person_on_sqlite(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'merge-unique');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Absorbed');
        PersonMerge::query()->create([
            'family_space_id' => $family->id,
            'survivor_person_id' => $survivor->id,
            'absorbed_person_id' => $absorbed->id,
            'status' => 'active',
            'provenance' => [],
            'merged_by' => $owner->id,
            'merged_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        PersonMerge::query()->create([
            'family_space_id' => $family->id,
            'survivor_person_id' => $survivor->id,
            'absorbed_person_id' => $absorbed->id,
            'status' => 'manual_correction_required',
            'provenance' => [],
            'merged_by' => $owner->id,
            'merged_at' => now(),
        ]);
    }

    public function test_database_rejects_a_duplicate_pending_merge_proposal_on_sqlite(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'proposal-unique');
        $survivor = $this->person($family, 'Survivor');
        $absorbed = $this->person($family, 'Absorbed');
        $attributes = [
            'family_space_id' => $family->id,
            'survivor_person_id' => $survivor->id,
            'absorbed_person_id' => $absorbed->id,
            'status' => 'pending',
            'proposed_by' => $owner->id,
        ];
        PersonMergeProposal::query()->create($attributes);

        $this->expectException(QueryException::class);
        PersonMergeProposal::query()->create($attributes);
    }

    private function person(FamilySpace $family, string $name): Person
    {
        return Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => $name]);
    }

    private function relationship(
        FamilySpace $family,
        Person $subject,
        Person $related,
        string $type,
        User $actor,
    ): PersonRelationship {
        return PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $subject->id,
            'related_person_id' => $related->id,
            'type' => $type,
            'status' => 'confirmed',
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
    }

    private function circleMembership(
        FamilySpace $family,
        FamilyCircle $circle,
        Person $person,
        User $actor,
    ): FamilyCirclePerson {
        return FamilyCirclePerson::query()->create([
            'family_space_id' => $family->id,
            'family_circle_id' => $circle->id,
            'person_id' => $person->id,
            'added_by' => $actor->id,
        ]);
    }

    private function link(FamilySpace $family, Person $person, User $user, User $actor): PersonAccountLink
    {
        return PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $user->id,
            'created_by' => $actor->id,
        ]);
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->addRole($family, $role)];
    }

    private function addRole(FamilySpace $family, FamilySpaceRole $role): User
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
