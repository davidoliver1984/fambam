<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Services\FamilySpaceManager;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FamilySpaceMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_family_space_creation_requires_the_separate_platform_capability(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/family-spaces', [
            'name' => 'Oliver Family',
            'slug' => 'oliver-family',
        ])->assertForbidden();
        $this->assertDatabaseCount('family_spaces', 0);

        $user->forceFill(['can_create_family_spaces' => true])->save();
        $this->actingAs($user)->postJson('/api/family-spaces', [
            'name' => 'Oliver Family',
            'slug' => 'oliver-family',
        ])->assertCreated()
            ->assertJsonPath('data.slug', 'oliver-family')
            ->assertJsonPath('data.role', FamilySpaceRole::Owner->value);

        $familySpace = FamilySpace::query()->sole();
        $this->assertDatabaseHas('family_space_memberships', [
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => FamilySpaceRole::Owner->value,
            'state' => MembershipState::Active->value,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'family_space.created',
            'actor_user_id' => $user->id,
            'subject_id' => $familySpace->id,
        ]);
    }

    public function test_creation_capability_commands_are_operator_attributed_and_idempotent(): void
    {
        $user = User::factory()->create();

        $this->artisan('fambam:grant-family-space-creation', ['email' => $user->email])
            ->assertFailed();
        $this->artisan('fambam:grant-family-space-creation', [
            'email' => $user->email,
            '--operator' => 'ops@example.test',
        ])->assertSuccessful();
        $this->artisan('fambam:grant-family-space-creation', [
            'email' => $user->email,
            '--operator' => 'ops@example.test',
        ])->assertSuccessful();

        $this->assertTrue($user->refresh()->can_create_family_spaces);
        $this->assertSame(1, AuditEvent::query()->where('action', 'family_space.creation_granted')->count());
        $grant = AuditEvent::query()->where('action', 'family_space.creation_granted')->sole();
        $this->assertSame([
            'actor_type' => 'console_operator',
            'operator_reference' => 'ops@example.test',
        ], $grant->metadata);

        $this->artisan('fambam:revoke-family-space-creation', [
            'email' => $user->email,
            '--operator' => 'ops@example.test',
        ])->assertSuccessful();
        $this->assertFalse($user->refresh()->can_create_family_spaces);
        $this->assertDatabaseHas('audit_events', ['action' => 'family_space.creation_revoked']);
    }

    public function test_owner_and_administrator_membership_authority_is_enforced(): void
    {
        [$familySpace, $owner, $ownerMembership] = $this->familyWithOwner();
        $administrator = User::factory()->create();
        $administratorMembership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $administrator->id,
            'role' => FamilySpaceRole::Administrator,
        ]);
        $member = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $removableMember = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'role' => FamilySpaceRole::Guest,
        ]);
        $manager = app(FamilySpaceManager::class);

        $manager->changeRole($administrator, $member, FamilySpaceRole::Contributor);
        $this->assertSame(FamilySpaceRole::Contributor, $member->refresh()->role);

        try {
            $manager->changeRole($administrator, $ownerMembership, FamilySpaceRole::Administrator);
            $this->fail('Administrator unexpectedly changed an Owner role.');
        } catch (AuthorizationException) {
            $this->assertSame(FamilySpaceRole::Owner, $ownerMembership->refresh()->role);
        }

        $manager->changeRole($owner, $member, FamilySpaceRole::Owner);
        $this->assertSame(FamilySpaceRole::Owner, $member->refresh()->role);
        $manager->changeRole($owner, $ownerMembership, FamilySpaceRole::Administrator);
        $this->assertSame(FamilySpaceRole::Administrator, $ownerMembership->refresh()->role);

        $manager->remove($administrator, $removableMember);
        $this->assertSame(MembershipState::Removed, $removableMember->refresh()->state);
        $this->assertSame($administrator->id, $removableMember->removed_by);
        $this->assertNotNull($removableMember->removed_at);
        $this->assertDatabaseHas('audit_events', ['action' => 'family_space.member_removed']);
        $this->assertSame(FamilySpaceRole::Administrator, $administratorMembership->role);
    }

    public function test_application_layer_refuses_to_remove_or_demote_the_last_owner(): void
    {
        [, $owner, $membership] = $this->familyWithOwner();
        $manager = app(FamilySpaceManager::class);

        foreach ([
            fn () => $manager->changeRole($owner, $membership, FamilySpaceRole::Administrator),
            fn () => $manager->remove($owner, $membership),
        ] as $operation) {
            try {
                $operation();
                $this->fail('The last Owner invariant was not enforced.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('membership', $exception->errors());
            }
        }

        $this->assertSame(FamilySpaceRole::Owner, $membership->refresh()->role);
        $this->assertSame(MembershipState::Active, $membership->state);
    }

    public function test_postgres_constraint_independently_rejects_direct_last_owner_removal(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL-specific deferred constraint regression.');
        }

        $owner = User::factory()->canCreateFamilySpaces()->create();
        $familySpace = app(FamilySpaceManager::class)->create(
            $owner,
            'Constraint Family',
            'constraint-family',
            Request::create('/api/family-spaces', 'POST'),
        );
        $membership = $familySpace->memberships()->sole();

        try {
            DB::transaction(function () use ($membership): void {
                FamilySpaceMembership::query()
                    ->whereKey($membership->id)
                    ->update(['state' => MembershipState::Removed->value]);
                DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
            });
            $this->fail('The database accepted direct removal of the last Owner.');
        } catch (QueryException) {
            $this->assertSame(MembershipState::Active, $membership->refresh()->state);
        }
    }

    /** @return array{FamilySpace, User, FamilySpaceMembership} */
    private function familyWithOwner(): array
    {
        $familySpace = FamilySpace::factory()->create();
        $owner = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
            'state' => MembershipState::Active,
        ]);

        return [$familySpace, $owner, $membership];
    }
}
