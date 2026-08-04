<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\User;
use App\Services\FamilySpaceManager;
use App\Services\InvitationManager;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PostgresRowLevelSecurityTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('The PostgreSQL RLS suite requires runtime and administrative connections.');
        }

        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared(<<<'SQL'
TRUNCATE TABLE invitation_claims, invitations, audit_events, family_space_memberships,
    family_spaces, sessions, password_reset_tokens, users RESTART IDENTITY CASCADE;
DROP TABLE IF EXISTS rls_test_records;
CREATE TABLE rls_test_records (
    id bigserial PRIMARY KEY,
    family_space_id char(26) NOT NULL,
    body text NOT NULL
);
ALTER TABLE rls_test_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE rls_test_records FORCE ROW LEVEL SECURITY;
CREATE POLICY ordinary_tenant_isolation ON rls_test_records
USING (family_space_id = app_current_family_space_id())
WITH CHECK (family_space_id = app_current_family_space_id());
SQL);

        $runtimeRole = $this->quotedIdentifier((string) config('database.runtime_role'));
        $this->admin->unprepared(
            "GRANT SELECT, INSERT, UPDATE, DELETE ON rls_test_records TO {$runtimeRole}; "
            ."GRANT USAGE, SELECT ON SEQUENCE rls_test_records_id_seq TO {$runtimeRole};",
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            $this->admin->statement('DROP TABLE IF EXISTS rls_test_records');
            DB::purge('pgsql_admin');
        }

        parent::tearDown();
    }

    public function test_runtime_role_and_forced_rls_are_active(): void
    {
        $role = DB::selectOne(<<<'SQL'
SELECT current_user AS name, rolname, rolsuper, rolbypassrls
FROM pg_roles
WHERE rolname = current_user
SQL);

        $this->assertSame(config('database.runtime_role'), $role->name);
        $this->assertFalse($role->rolsuper);
        $this->assertFalse($role->rolbypassrls);

        $tables = DB::select(<<<'SQL'
SELECT relname, relrowsecurity, relforcerowsecurity
FROM pg_class
WHERE relname IN ('family_spaces', 'family_space_memberships', 'rls_test_records')
ORDER BY relname
SQL);

        $this->assertCount(3, $tables);
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity, "{$table->relname} does not have RLS enabled.");
            $this->assertTrue($table->relforcerowsecurity, "{$table->relname} does not force RLS.");
        }
    }

    public function test_registry_and_membership_policies_resolve_without_recursion(): void
    {
        [$aliceId, $aliceFamily, $aliceMembership] = $this->createOwnedFamily('alice-family');
        [$bobId, $bobFamily] = $this->createOwnedFamily('bob-family');
        $aliceSecondMembership = $this->addMembership($aliceFamily, $bobId, FamilySpaceRole::Member);

        DB::beginTransaction();
        $context = app(DatabaseTenantContext::class);
        $context->establishUser($aliceId);

        $this->assertSame([$aliceFamily], DB::table('family_spaces')->pluck('id')->all());
        $this->assertSame([$aliceMembership], DB::table('family_space_memberships')->pluck('id')->all());

        $context->establishFamilySpace($aliceFamily);
        $this->assertEqualsCanonicalizing(
            [$aliceMembership, $aliceSecondMembership],
            DB::table('family_space_memberships')->pluck('id')->all(),
        );
        $this->assertFalse(DB::table('family_spaces')->where('id', $bobFamily)->exists());
        DB::rollBack();
    }

    public function test_authenticated_route_establishes_and_clears_database_context(): void
    {
        [$userId] = $this->createOwnedFamily('route-family');
        $this->createOwnedFamily('inaccessible-family');
        $user = User::query()->findOrFail($userId);

        $this->actingAs($user)
            ->getJson('/api/families/route-family')
            ->assertOk()
            ->assertJsonPath('data.slug', 'route-family');
        $this->actingAs($user)
            ->getJson('/api/families/inaccessible-family')
            ->assertNotFound();

        $this->assertNull(DB::scalar('SELECT app_current_user_id()'));
        $this->assertNull(DB::scalar('SELECT app_current_family_space_id()'));
    }

    public function test_ordinary_tenant_policy_fails_closed_for_reads_and_writes(): void
    {
        [, $firstFamily] = $this->createOwnedFamily('first-family');
        [, $secondFamily] = $this->createOwnedFamily('second-family');
        $this->admin->table('rls_test_records')->insert([
            ['family_space_id' => $firstFamily, 'body' => 'first'],
            ['family_space_id' => $secondFamily, 'body' => 'second'],
        ]);

        $this->assertSame([], DB::table('rls_test_records')->pluck('body')->all());
        $this->assertRlsRejects(fn () => DB::table('rls_test_records')->insert([
            'family_space_id' => $firstFamily,
            'body' => 'missing-context',
        ]));

        DB::beginTransaction();
        app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
        $this->assertSame(['first'], DB::table('rls_test_records')->pluck('body')->all());
        DB::table('rls_test_records')->insert(['family_space_id' => $firstFamily, 'body' => 'allowed']);
        $this->assertSame(2, DB::table('rls_test_records')->count());
        DB::rollBack();

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('rls_test_records')->insert([
                'family_space_id' => $secondFamily,
                'body' => 'cross-tenant',
            ]);
        });

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('rls_test_records')
                ->where('family_space_id', $firstFamily)
                ->update(['family_space_id' => $secondFamily]);
        });
    }

    public function test_membership_writes_require_an_authorized_tenant_context(): void
    {
        [$ownerId, $familySpace] = $this->createOwnedFamily('managed-family');
        [$otherOwnerId, $otherFamily] = $this->createOwnedFamily('other-family');
        $memberId = $this->createUser('member@example.test');
        $memberMembership = $this->addMembership($familySpace, $memberId, FamilySpaceRole::Member);
        $otherMembership = $this->addMembership($otherFamily, $memberId, FamilySpaceRole::Member);

        DB::beginTransaction();
        $context = app(DatabaseTenantContext::class);
        $context->establishUser($memberId);
        $context->establishFamilySpace($familySpace);
        $this->assertSame(0, DB::table('family_space_memberships')
            ->where('id', $memberMembership)
            ->update(['role' => FamilySpaceRole::Contributor->value]));
        DB::rollBack();

        DB::beginTransaction();
        $context->establishUser($ownerId);
        $context->establishFamilySpace($familySpace, canManageMemberships: true);
        $this->assertSame(1, DB::table('family_space_memberships')
            ->where('id', $memberMembership)
            ->update(['role' => FamilySpaceRole::Contributor->value]));
        $this->assertSame(0, DB::table('family_space_memberships')
            ->where('id', $otherMembership)
            ->update(['role' => FamilySpaceRole::Guest->value]));
        DB::rollBack();

        $this->assertNotSame($ownerId, $otherOwnerId);
    }

    public function test_transaction_local_context_does_not_leak_after_commit_or_rollback(): void
    {
        [$userId, $familySpace] = $this->createOwnedFamily('context-family');
        $context = app(DatabaseTenantContext::class);

        DB::beginTransaction();
        $context->establishUser($userId);
        $context->establishFamilySpace($familySpace);
        DB::commit();
        $this->assertNull(DB::scalar('SELECT app_current_user_id()'));
        $this->assertNull(DB::scalar('SELECT app_current_family_space_id()'));

        DB::beginTransaction();
        $context->establishUser($userId);
        $context->establishFamilySpace($familySpace);
        DB::rollBack();
        $this->assertNull(DB::scalar('SELECT app_current_user_id()'));
        $this->assertNull(DB::scalar('SELECT app_current_family_space_id()'));
    }

    public function test_creation_and_invitation_acceptance_remain_atomic_under_rls(): void
    {
        $creator = new User;
        $creator->forceFill([
            'name' => 'Creator',
            'email' => 'creator@example.test',
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
            'can_create_family_spaces' => true,
        ])->save();
        $request = Request::create('/api/family-spaces', 'POST');
        $familySpace = app(FamilySpaceManager::class)->create(
            $creator,
            'Created Family',
            'created-family',
            $request,
        );

        $this->assertSame(1, $this->admin->table('family_spaces')->count());
        $this->assertSame(1, $this->admin->table('family_space_memberships')->count());

        try {
            app(FamilySpaceManager::class)->create(
                $creator,
                'Duplicate Family',
                'created-family',
                $request,
            );
            $this->fail('Duplicate Family Space creation unexpectedly succeeded.');
        } catch (QueryException) {
            $this->assertSame(1, $this->admin->table('family_spaces')->count());
            $this->assertSame(1, $this->admin->table('family_space_memberships')->count());
        }

        $invitee = User::query()->create([
            'name' => 'Invitee',
            'email' => 'invitee@example.test',
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
        ]);
        $removedMembership = $this->addMembership($familySpace->id, $invitee->id, FamilySpaceRole::Guest);
        $this->admin->table('family_space_memberships')->where('id', $removedMembership)->update([
            'state' => MembershipState::Removed->value,
            'removed_at' => now(),
        ]);
        [$claimToken, $invitationId] = $this->createInvitationClaim($familySpace->id, $invitee);
        $acceptRequest = Request::create('/api/invitations/accept', 'POST');
        $acceptRequest->setUserResolver(fn (?string $guard = null): User => $invitee);

        app(InvitationManager::class)->accept($claimToken, [], $acceptRequest);

        $this->assertSame(MembershipState::Active->value, $this->admin->table('family_space_memberships')
            ->where('id', $removedMembership)
            ->value('state'));
        $this->assertSame('accepted', $this->admin->table('invitations')->where('id', $invitationId)->value('status'));

        [$rejectedClaim, $rejectedInvitation] = $this->createInvitationClaim($familySpace->id, $invitee);

        try {
            app(InvitationManager::class)->accept($rejectedClaim, [], $acceptRequest);
            $this->fail('Acceptance for an already-active member unexpectedly succeeded.');
        } catch (ValidationException) {
            $this->assertSame('pending', $this->admin->table('invitations')
                ->where('id', $rejectedInvitation)
                ->value('status'));
            $this->assertNull($this->admin->table('invitation_claims')
                ->where('invitation_id', $rejectedInvitation)
                ->value('used_at'));
            $this->assertSame(1, $this->admin->table('family_space_memberships')
                ->where('family_space_id', $familySpace->id)
                ->where('user_id', $invitee->id)
                ->count());
        }
    }

    /** @return array{int, string, string} */
    private function createOwnedFamily(string $slug): array
    {
        $userId = $this->createUser("{$slug}@example.test");
        $familySpaceId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();

        $this->admin->transaction(function () use ($userId, $familySpaceId, $membershipId, $slug): void {
            $now = now();
            $this->admin->table('family_spaces')->insert([
                'id' => $familySpaceId,
                'slug' => $slug,
                'name' => Str::headline($slug),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => $membershipId,
                'family_space_id' => $familySpaceId,
                'user_id' => $userId,
                'role' => FamilySpaceRole::Owner->value,
                'state' => MembershipState::Active->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        return [$userId, $familySpaceId, $membershipId];
    }

    private function createUser(string $email): int
    {
        return (int) $this->admin->table('users')->insertGetId([
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'not-used-in-this-test',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addMembership(string $familySpaceId, int $userId, FamilySpaceRole $role): string
    {
        $membershipId = (string) Str::ulid();
        $this->admin->table('family_space_memberships')->insert([
            'id' => $membershipId,
            'family_space_id' => $familySpaceId,
            'user_id' => $userId,
            'role' => $role->value,
            'state' => MembershipState::Active->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $membershipId;
    }

    /** @return array{string, int} */
    private function createInvitationClaim(string $familySpaceId, User $invitee): array
    {
        $claimToken = Str::random(64);
        $invitationId = (int) $this->admin->table('invitations')->insertGetId([
            'family_space_id' => $familySpaceId,
            'email' => $invitee->email,
            'role' => FamilySpaceRole::Member->value,
            'invited_by' => $this->admin->table('family_space_memberships')
                ->where('family_space_id', $familySpaceId)
                ->where('role', FamilySpaceRole::Owner->value)
                ->value('user_id'),
            'status' => 'pending',
            'expires_at' => now()->addDay(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->admin->table('invitation_claims')->insert([
            'invitation_id' => $invitationId,
            'token_hash' => hash('sha256', $claimToken),
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$claimToken, $invitationId];
    }

    /** @param callable(): mixed $operation */
    private function assertRlsRejects(callable $operation): void
    {
        DB::beginTransaction();

        try {
            $operation();
            $this->fail('PostgreSQL RLS unexpectedly accepted the write.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
        } finally {
            DB::rollBack();
        }
    }

    private function quotedIdentifier(string $identifier): string
    {
        $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier);

        return '"'.$identifier.'"';
    }
}
