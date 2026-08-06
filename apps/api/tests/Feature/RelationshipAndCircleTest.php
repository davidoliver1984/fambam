<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RelationshipAndCircleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authoritative_edges_are_canonical_and_inverse_wording_is_derived(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'relationships');
        $alice = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Alice']);
        $beth = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Beth']);

        $this->actingAs($owner)->postJson("/api/families/relationships/people/{$alice->id}/relationships", [
            'related_person_id' => $beth->id,
            'type' => 'parent_of',
            'context' => 'Family records',
        ])->assertCreated()->assertJsonPath('data.label', 'parent');

        $this->actingAs($owner)->getJson("/api/families/relationships/people/{$beth->id}/relationships")
            ->assertOk()
            ->assertJsonPath('data.0.label', 'child')
            ->assertJsonPath('data.0.other_person.preferred_name', 'Alice');

        $this->actingAs($owner)->postJson("/api/families/relationships/people/{$beth->id}/relationships", [
            'related_person_id' => $alice->id,
            'type' => 'sibling_of',
        ])->assertUnprocessable()->assertJsonValidationErrors('relationship');

        $this->assertDatabaseCount('person_relationships', 1);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_created']);
    }

    public function test_relationship_validation_rejects_self_duplicates_inverse_cycles_and_conflicts(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'validation');
        $a = Person::factory()->create(['family_space_id' => $family->id]);
        $b = Person::factory()->create(['family_space_id' => $family->id]);
        $url = "/api/families/validation/people/{$a->id}/relationships";

        $this->actingAs($owner)->postJson($url, [
            'related_person_id' => $a->id,
            'type' => 'parent_of',
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, [
            'related_person_id' => $b->id,
            'type' => 'parent_of',
        ])->assertCreated();
        $this->actingAs($owner)->postJson($url, [
            'related_person_id' => $b->id,
            'type' => 'parent_of',
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson("/api/families/validation/people/{$b->id}/relationships", [
            'related_person_id' => $a->id,
            'type' => 'parent_of',
        ])->assertUnprocessable();
        $this->actingAs($owner)->postJson($url, [
            'related_person_id' => $b->id,
            'type' => 'partner_of',
        ])->assertUnprocessable();
    }

    public function test_member_proposal_is_not_authoritative_and_approval_revalidates_current_state(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'proposals');
        $member = $this->addRole($family, FamilySpaceRole::Member);
        $a = Person::factory()->create(['family_space_id' => $family->id]);
        $b = Person::factory()->create(['family_space_id' => $family->id]);

        $this->actingAs($member)->postJson("/api/families/proposals/people/{$a->id}/relationships", [
            'related_person_id' => $b->id,
            'type' => 'parent_of',
        ])->assertForbidden();

        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/proposals/people/{$a->id}/relationship-proposals", [
                'action' => 'create',
                'related_person_id' => $b->id,
                'type' => 'parent_of',
            ])->assertCreated()->json('data.id');

        $this->assertDatabaseCount('person_relationships', 0);
        $authoritativeId = $this->actingAs($owner)
            ->postJson("/api/families/proposals/people/{$a->id}/relationships", [
                'related_person_id' => $b->id,
                'type' => 'partner_of',
            ])->assertCreated()->json('data.id');
        $this->actingAs($member)
            ->postJson("/api/families/proposals/people/{$a->id}/relationship-proposals", [
                'action' => 'create',
                'relationship_id' => $authoritativeId,
                'related_person_id' => $b->id,
                'type' => 'partner_of',
            ])->assertUnprocessable()->assertJsonValidationErrors('relationship');
        $this->actingAs($owner)
            ->postJson("/api/families/proposals/people/{$a->id}/relationship-proposals/{$proposalId}/approve")
            ->assertUnprocessable()->assertJsonValidationErrors('relationship');

        $this->assertDatabaseCount('person_relationships', 1);
        $this->assertDatabaseHas('relationship_proposals', ['id' => $proposalId, 'status' => 'pending']);
    }

    public function test_owner_can_approve_reject_dispute_replace_and_remove_relationship_proposals(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'review-relationships');
        $member = $this->addRole($family, FamilySpaceRole::Member);
        $a = Person::factory()->create(['family_space_id' => $family->id]);
        $b = Person::factory()->create(['family_space_id' => $family->id]);

        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals", [
                'action' => 'create', 'related_person_id' => $b->id, 'type' => 'guardian_of',
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals/{$proposalId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $relationship = PersonRelationship::query()->firstOrFail();

        $replacementId = $this->actingAs($member)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals", [
                'action' => 'replace',
                'relationship_id' => $relationship->id,
                'related_person_id' => $b->id,
                'type' => 'close_family_friend_of',
                'context' => 'Corrected by the family',
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals/{$replacementId}/approve")
            ->assertOk();
        $this->assertSame('close_family_friend_of', $relationship->refresh()->type->value);
        $this->actingAs($owner)
            ->patchJson("/api/families/review-relationships/relationships/{$relationship->id}", [
                'subject_person_id' => $a->id,
                'related_person_id' => $b->id,
                'type' => 'guardian_of',
                'context' => 'Authoritative correction',
            ])->assertOk()->assertJsonPath('data.type', 'guardian_of');

        $disputeId = $this->actingAs($member)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals", [
                'action' => 'dispute', 'relationship_id' => $relationship->id,
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals/{$disputeId}/approve")
            ->assertOk();
        $this->assertSame('disputed', $relationship->refresh()->status->value);

        $removeId = $this->actingAs($member)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals", [
                'action' => 'remove', 'relationship_id' => $relationship->id,
            ])->assertCreated()->json('data.id');
        $this->actingAs($owner)
            ->postJson("/api/families/review-relationships/people/{$a->id}/relationship-proposals/{$removeId}/reject")
            ->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->assertDatabaseHas('person_relationships', ['id' => $relationship->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_proposal_approved']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_proposal_rejected']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_disputed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_replaced']);

        $this->actingAs($owner)
            ->deleteJson("/api/families/review-relationships/relationships/{$relationship->id}")
            ->assertNoContent();
        $this->assertDatabaseMissing('person_relationships', ['id' => $relationship->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.relationship_removed']);
    }

    public function test_family_circles_are_flat_people_only_groups_managed_by_members(): void
    {
        [$family] = $this->familyWithRole(FamilySpaceRole::Owner, 'circles');
        $member = $this->addRole($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Family Friend']);

        $circleId = $this->actingAs($member)->postJson('/api/families/circles/circles', [
            'name' => 'Wedding Group',
            'description' => 'Presentation grouping',
        ])->assertCreated()->json('data.id');
        $this->actingAs($member)->postJson("/api/families/circles/circles/{$circleId}/people", [
            'person_id' => $person->id,
        ])->assertCreated()->assertJsonPath('data.people.0.preferred_name', 'Family Friend');
        $this->actingAs($member)->getJson('/api/families/circles/circles')
            ->assertOk()->assertJsonPath('data.0.people.0.id', $person->id);
        $this->actingAs($member)->patchJson("/api/families/circles/circles/{$circleId}", [
            'name' => 'Close Family Friends',
        ])->assertOk()->assertJsonPath('data.name', 'Close Family Friends');
        $this->actingAs($member)
            ->deleteJson("/api/families/circles/circles/{$circleId}/people/{$person->id}")
            ->assertNoContent();
        $this->assertDatabaseHas('audit_events', ['action' => 'person.family_circle_created']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.family_circle_person_added']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.family_circle_changed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.family_circle_person_removed']);

        foreach ([FamilySpaceRole::Contributor, FamilySpaceRole::Guest] as $role) {
            $this->actingAs($this->addRole($family, $role))->getJson('/api/families/circles/circles')->assertForbidden();
        }

        $this->actingAs($member)->deleteJson("/api/families/circles/circles/{$circleId}")->assertNoContent();
        $this->assertDatabaseHas('audit_events', ['action' => 'person.family_circle_removed']);
    }

    public function test_relationship_and_circle_identifiers_fail_closed_across_tenants(): void
    {
        [$first, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'tenant-one');
        [$second] = $this->familyWithRole(FamilySpaceRole::Owner, 'tenant-two');
        $firstPerson = Person::factory()->create(['family_space_id' => $first->id]);
        $secondPerson = Person::factory()->create(['family_space_id' => $second->id]);

        $this->actingAs($owner)->postJson("/api/families/tenant-one/people/{$firstPerson->id}/relationships", [
            'related_person_id' => $secondPerson->id,
            'type' => 'sibling_of',
        ])->assertNotFound();
        $this->actingAs($owner)->postJson('/api/families/tenant-one/circles', ['name' => 'Local'])
            ->assertCreated();
        $circleId = $this->actingAs($owner)->getJson('/api/families/tenant-one/circles')->json('data.0.id');
        $this->actingAs($owner)->postJson("/api/families/tenant-one/circles/{$circleId}/people", [
            'person_id' => $secondPerson->id,
        ])->assertNotFound();
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
