<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantRouteContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_scoped_request_returns_unauthorized_without_a_web_redirect(): void
    {
        $this->get('/api/families/private-family')->assertUnauthorized();
    }

    public function test_unknown_and_inaccessible_family_slugs_have_the_same_response(): void
    {
        config(['app.debug' => false]);
        $user = User::factory()->create();
        $inaccessible = FamilySpace::factory()->create(['slug' => 'private-family']);
        FamilySpaceMembership::factory()->create(['family_space_id' => $inaccessible->id]);

        $unknownResponse = $this->actingAs($user)->getJson('/api/families/unknown-family');
        $inaccessibleResponse = $this->actingAs($user)->getJson('/api/families/private-family');

        $unknownResponse->assertNotFound();
        $inaccessibleResponse->assertNotFound();
        $this->assertSame($unknownResponse->json(), $inaccessibleResponse->json());
    }

    public function test_active_membership_resolves_the_slug_and_removed_membership_does_not(): void
    {
        $user = User::factory()->create();
        $familySpace = FamilySpace::factory()->create(['slug' => 'oliver-family']);
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => FamilySpaceRole::Contributor,
            'state' => MembershipState::Active,
        ]);

        $this->actingAs($user)
            ->getJson('/api/families/oliver-family')
            ->assertOk()
            ->assertJsonPath('data.id', $familySpace->id)
            ->assertJsonPath('data.slug', 'oliver-family')
            ->assertJsonPath('data.role', FamilySpaceRole::Contributor->value);

        $membership->update([
            'state' => MembershipState::Removed,
            'removed_at' => now(),
        ]);

        $this->actingAs($user)->getJson('/api/families/oliver-family')->assertNotFound();
    }

    public function test_tenant_context_is_cleared_after_a_scoped_request(): void
    {
        $user = User::factory()->create();
        $familySpace = FamilySpace::factory()->create(['slug' => 'context-family']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
        ]);
        $tenantContext = app(TenantContext::class);

        $this->actingAs($user)->getJson('/api/families/context-family')->assertOk();

        $this->assertFalse($tenantContext->isEstablished());
    }

    public function test_family_space_listing_is_explicitly_limited_to_active_memberships(): void
    {
        $user = User::factory()->create();
        $visible = FamilySpace::factory()->create(['slug' => 'visible-family']);
        $removed = FamilySpace::factory()->create(['slug' => 'removed-family']);
        $unrelated = FamilySpace::factory()->create(['slug' => 'unrelated-family']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $visible->id,
            'user_id' => $user->id,
        ]);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $removed->id,
            'user_id' => $user->id,
            'state' => MembershipState::Removed,
            'removed_at' => now(),
        ]);
        FamilySpaceMembership::factory()->create(['family_space_id' => $unrelated->id]);

        $response = $this->actingAs($user)->getJson('/api/family-spaces')->assertOk();

        $this->assertSame(['visible-family'], $response->json('data.*.slug'));
    }

    public function test_member_gets_forbidden_only_after_successful_tenant_resolution(): void
    {
        $member = User::factory()->create();
        $familySpace = FamilySpace::factory()->create(['slug' => 'member-family']);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
        ]);

        $this->actingAs($member)
            ->getJson('/api/families/member-family/invitations')
            ->assertForbidden();
        $this->actingAs($member)
            ->getJson('/api/families/member-family/memberships')
            ->assertForbidden();
    }

    public function test_nested_resources_are_resolved_only_inside_the_active_family_space(): void
    {
        $owner = User::factory()->create();
        $first = $this->familyOwnedBy($owner, 'first-family');
        $second = $this->familyOwnedBy($owner, 'second-family');
        $secondInvitation = Invitation::factory()->create(['family_space_id' => $second->id]);
        $secondMember = FamilySpaceMembership::factory()->create(['family_space_id' => $second->id]);

        $this->actingAs($owner)
            ->postJson("/api/families/first-family/invitations/{$secondInvitation->id}/revoke")
            ->assertNotFound();
        $this->actingAs($owner)
            ->patchJson("/api/families/first-family/memberships/{$secondMember->id}", [
                'role' => FamilySpaceRole::Contributor->value,
            ])
            ->assertNotFound();

        $this->assertSame($second->id, $secondInvitation->refresh()->family_space_id);
        $this->assertSame(FamilySpaceRole::Member, $secondMember->refresh()->role);
        $this->assertSame($first->id, FamilySpace::query()->where('slug', 'first-family')->value('id'));
    }

    public function test_membership_routes_apply_role_policy_before_domain_owner_rules(): void
    {
        $owner = User::factory()->create();
        $familySpace = $this->familyOwnedBy($owner, 'managed-family');
        $ownerMembership = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpace->id)
            ->where('user_id', $owner->id)
            ->sole();
        $administrator = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $administrator->id,
            'role' => FamilySpaceRole::Administrator,
        ]);
        $member = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'role' => FamilySpaceRole::Member,
        ]);

        $this->actingAs($administrator)
            ->getJson('/api/families/managed-family/memberships')
            ->assertOk()
            ->assertJsonCount(3, 'data');
        $this->actingAs($administrator)
            ->patchJson("/api/families/managed-family/memberships/{$member->id}", [
                'role' => FamilySpaceRole::Contributor->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.role', FamilySpaceRole::Contributor->value);
        $this->actingAs($administrator)
            ->patchJson("/api/families/managed-family/memberships/{$ownerMembership->id}", [
                'role' => FamilySpaceRole::Member->value,
            ])
            ->assertForbidden();

        $this->assertSame(FamilySpaceRole::Owner, $ownerMembership->refresh()->role);
    }

    private function familyOwnedBy(User $owner, string $slug): FamilySpace
    {
        $familySpace = FamilySpace::factory()->create(['slug' => $slug]);
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);

        return $familySpace;
    }
}
