<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PersonAccountClaimStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class PersonAccountLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_self_claim_requires_owner_or_administrator_approval(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('claim-family');
        [$member, $memberMembership] = $this->addMember($familySpace, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $familySpace->id]);

        $claim = $this->actingAs($member)
            ->postJson("/api/families/claim-family/people/{$person->id}/account-link-claims")
            ->assertCreated()
            ->assertJsonPath('data.account.name', $member->name)
            ->assertJsonPath('data.status', PersonAccountClaimStatus::Pending->value)
            ->json('data');

        $this->assertDatabaseMissing('person_account_links', ['person_id' => $person->id]);
        $this->actingAs($member)
            ->getJson("/api/families/claim-family/people/{$person->id}/account-link-claims")
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson("/api/families/claim-family/people/{$person->id}/account-link-claims")
            ->assertOk()
            ->assertJsonPath('data.0.id', $claim['id']);

        $this->actingAs($owner)
            ->postJson("/api/families/claim-family/people/{$person->id}/account-link-claims/{$claim['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.account.name', $member->name);

        $this->actingAs($member)
            ->getJson("/api/families/claim-family/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.account_link.account.is_current_user', true)
            ->assertJsonPath('data.account_link.account.name', $member->name);
        $this->assertDatabaseHas('person_account_links', [
            'family_space_id' => $familySpace->id,
            'person_id' => $person->id,
            'user_id' => $member->id,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.account_link_claim_proposed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.account_link_claim_approved']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.account_link_created']);
        $this->assertSame(MembershipState::Active, $memberMembership->state);
    }

    public function test_contributor_and_guest_cannot_claim_through_a_guessed_person_identifier(): void
    {
        [$familySpace] = $this->familyWithOwner('limited-claim-family');
        [$contributor] = $this->addMember($familySpace, FamilySpaceRole::Contributor);
        [$guest] = $this->addMember($familySpace, FamilySpaceRole::Guest);
        $contributorPerson = Person::factory()->create(['family_space_id' => $familySpace->id]);
        $guestPerson = Person::factory()->create(['family_space_id' => $familySpace->id]);

        $this->actingAs($contributor)
            ->getJson('/api/families/limited-claim-family/people')
            ->assertForbidden();
        $this->actingAs($contributor)
            ->postJson("/api/families/limited-claim-family/people/{$contributorPerson->id}/account-link-claims")
            ->assertForbidden();
        $this->actingAs($guest)
            ->postJson("/api/families/limited-claim-family/people/{$guestPerson->id}/account-link-claims")
            ->assertForbidden();
    }

    public function test_authoritative_assignment_enforces_and_atomically_corrects_one_to_one_links(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('correction-family');
        [$firstUser, $firstMembership] = $this->addMember($familySpace, FamilySpaceRole::Member);
        [$secondUser, $secondMembership] = $this->addMember($familySpace, FamilySpaceRole::Member);
        $firstPerson = Person::factory()->create(['family_space_id' => $familySpace->id]);
        $secondPerson = Person::factory()->create(['family_space_id' => $familySpace->id]);

        $claimId = $this->actingAs($firstUser)
            ->postJson("/api/families/correction-family/people/{$firstPerson->id}/account-link-claims")
            ->assertCreated()
            ->json('data.id');
        $this->assign($owner, 'correction-family', $firstPerson, $firstMembership)->assertOk();
        $this->assertDatabaseHas('person_account_claims', [
            'id' => $claimId,
            'status' => PersonAccountClaimStatus::Rejected->value,
        ]);
        $this->assign($owner, 'correction-family', $secondPerson, $secondMembership)->assertOk();
        $this->assign($owner, 'correction-family', $firstPerson, $secondMembership)
            ->assertOk()
            ->assertJsonPath('data.account.id', $secondUser->id);

        $this->assertDatabaseCount('person_account_links', 1);
        $this->assertDatabaseHas('person_account_links', [
            'person_id' => $firstPerson->id,
            'user_id' => $secondUser->id,
        ]);
        $this->assertDatabaseMissing('person_account_links', ['user_id' => $firstUser->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.account_link_corrected']);
    }

    public function test_link_survives_membership_removal_and_account_revocation_until_explicit_unlink(): void
    {
        [$familySpace, $owner] = $this->familyWithOwner('retention-family');
        [$member, $membership] = $this->addMember($familySpace, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $familySpace->id]);
        $this->assign($owner, 'retention-family', $person, $membership)->assertOk();

        $this->actingAs($owner)
            ->deleteJson("/api/families/retention-family/memberships/{$membership->id}")
            ->assertOk();
        $member->forceFill(['revoked_at' => now()])->save();
        $this->assertDatabaseHas('person_account_links', [
            'person_id' => $person->id,
            'user_id' => $member->id,
        ]);

        $this->actingAs($owner)
            ->deleteJson("/api/families/retention-family/people/{$person->id}/account-link")
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->assertDatabaseMissing('person_account_links', ['person_id' => $person->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.account_link_removed']);
    }

    public function test_the_same_account_can_link_to_one_person_in_each_family_space(): void
    {
        [$firstFamily, $firstOwner] = $this->familyWithOwner('first-account-family');
        [$account, $firstMembership] = $this->addMember($firstFamily, FamilySpaceRole::Member);
        [$secondFamily, $secondOwner] = $this->familyWithOwner('second-account-family');
        $secondMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $secondFamily->id,
            'user_id' => $account->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $firstPerson = Person::factory()->create(['family_space_id' => $firstFamily->id]);
        $secondPerson = Person::factory()->create(['family_space_id' => $secondFamily->id]);

        $this->assign($firstOwner, 'first-account-family', $firstPerson, $firstMembership)->assertOk();
        $this->assign($secondOwner, 'second-account-family', $secondPerson, $secondMembership)->assertOk();

        $this->assertDatabaseCount('person_account_links', 2);
        $this->assertDatabaseHas('person_account_links', [
            'family_space_id' => $firstFamily->id,
            'person_id' => $firstPerson->id,
            'user_id' => $account->id,
        ]);
        $this->assertDatabaseHas('person_account_links', [
            'family_space_id' => $secondFamily->id,
            'person_id' => $secondPerson->id,
            'user_id' => $account->id,
        ]);
    }

    public function test_claim_approval_rechecks_membership_and_cross_tenant_identifiers_fail_closed(): void
    {
        [$firstFamily, $firstOwner] = $this->familyWithOwner('first-link-family');
        [$member, $membership] = $this->addMember($firstFamily, FamilySpaceRole::Member);
        $firstPerson = Person::factory()->create(['family_space_id' => $firstFamily->id]);
        $claimId = $this->actingAs($member)
            ->postJson("/api/families/first-link-family/people/{$firstPerson->id}/account-link-claims")
            ->assertCreated()
            ->json('data.id');
        $membership->update(['state' => MembershipState::Removed, 'removed_at' => now()]);

        $this->actingAs($firstOwner)
            ->postJson("/api/families/first-link-family/people/{$firstPerson->id}/account-link-claims/{$claimId}/approve")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account_link');

        [$secondFamily] = $this->familyWithOwner('second-link-family');
        [, $secondMembership] = $this->addMember($secondFamily, FamilySpaceRole::Member);
        $secondPerson = Person::factory()->create(['family_space_id' => $secondFamily->id]);
        $this->assign($firstOwner, 'first-link-family', $secondPerson, $secondMembership)->assertNotFound();
        $this->actingAs($firstOwner)
            ->postJson("/api/families/first-link-family/people/{$secondPerson->id}/account-link-claims")
            ->assertNotFound();
    }

    /** @return array{FamilySpace, User} */
    private function familyWithOwner(string $slug): array
    {
        $familySpace = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);

        return [$familySpace, $owner];
    }

    /** @return array{User, FamilySpaceMembership} */
    private function addMember(FamilySpace $familySpace, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$user, $membership];
    }

    /** @return TestResponse<Response> */
    private function assign(
        User $owner,
        string $familySlug,
        Person $person,
        FamilySpaceMembership $membership,
    ): TestResponse {
        return $this->actingAs($owner)->putJson(
            "/api/families/{$familySlug}/people/{$person->id}/account-link",
            ['membership_id' => $membership->id],
        );
    }
}
