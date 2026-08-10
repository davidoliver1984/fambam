<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Jobs\DeleteFamilySpace;
use App\Models\Invitation;
use App\Models\User;
use App\Services\FamilySpaceDeletionManager;
use App\Services\FamilySpaceManager;
use App\Services\InvitationManager;
use App\Services\MembershipInvitationAcceptor;
use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
TRUNCATE TABLE media_uploads, person_merge_proposals, person_merges,
    family_circle_people, family_circles, relationship_proposals, person_relationships,
    person_account_claims, person_account_links, person_detail_proposals, people,
    invitation_claims, invitations, audit_events, family_space_memberships,
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
WHERE relname IN (
    'audit_events', 'family_spaces', 'family_space_memberships',
    'people', 'person_detail_proposals', 'person_account_links', 'person_account_claims',
    'person_relationships', 'relationship_proposals', 'family_circles', 'family_circle_people',
    'person_merges', 'person_merge_proposals', 'media_uploads',
    'rls_test_records'
)
ORDER BY relname
SQL);

        $this->assertCount(15, $tables);
        foreach ($tables as $table) {
            $this->assertTrue($table->relrowsecurity, "{$table->relname} does not have RLS enabled.");
            $this->assertTrue($table->relforcerowsecurity, "{$table->relname} does not force RLS.");
        }
    }

    public function test_audit_runtime_access_is_insert_only_with_no_read_or_mutation_policies(): void
    {
        $privileges = DB::selectOne(<<<'SQL'
SELECT
    has_table_privilege(current_user, 'audit_events', 'INSERT') AS can_insert,
    has_table_privilege(current_user, 'audit_events', 'SELECT') AS can_select,
    has_table_privilege(current_user, 'audit_events', 'UPDATE') AS can_update,
    has_table_privilege(current_user, 'audit_events', 'DELETE') AS can_delete
SQL);

        $this->assertTrue($privileges->can_insert);
        $this->assertFalse($privileges->can_select);
        $this->assertFalse($privileges->can_update);
        $this->assertFalse($privileges->can_delete);

        $unexpectedPolicies = $this->admin->table('pg_policies')
            ->where('schemaname', 'public')
            ->where('tablename', 'audit_events')
            ->whereIn('cmd', ['SELECT', 'UPDATE', 'DELETE'])
            ->count();
        $this->assertSame(0, $unexpectedPolicies);

        try {
            DB::table('audit_events')->count();
            $this->fail('The runtime role unexpectedly read audit rows.');
        } catch (QueryException $exception) {
            $this->assertSame('42501', $exception->errorInfo[0]);
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

    public function test_media_uploads_use_the_standard_class_c_tenant_boundary(): void
    {
        [$firstOwner, $firstFamily] = $this->createOwnedFamily('first-media-rls');
        [$secondOwner, $secondFamily] = $this->createOwnedFamily('second-media-rls');
        $now = now();

        foreach ([[$firstOwner, $firstFamily], [$secondOwner, $secondFamily]] as [$ownerId, $familyId]) {
            $uploadId = (string) Str::ulid();
            $this->admin->table('media_uploads')->insert([
                'id' => $uploadId,
                'family_space_id' => $familyId,
                'user_id' => $ownerId,
                'state' => 'initiated',
                'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
                'client_filename' => 'family.jpg',
                'upload_method' => 'single',
                'idempotency_key' => "rls-{$uploadId}",
                'request_fingerprint' => hash('sha256', $uploadId),
                'correlation_id' => (string) Str::uuid(),
                'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $this->assertSame([], DB::table('media_uploads')->pluck('family_space_id')->all());

        DB::beginTransaction();
        app(DatabaseTenantContext::class)->establishUser($firstOwner);
        app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
        $this->assertSame([$firstFamily], DB::table('media_uploads')->pluck('family_space_id')->all());
        DB::rollBack();

        $this->assertRlsRejects(function () use ($firstOwner, $firstFamily, $secondFamily, $now): void {
            app(DatabaseTenantContext::class)->establishUser($firstOwner);
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            $uploadId = (string) Str::ulid();
            DB::table('media_uploads')->insert([
                'id' => $uploadId,
                'family_space_id' => $secondFamily,
                'user_id' => $firstOwner,
                'state' => 'initiated',
                'staging_object_key' => "families/{$secondFamily}/media-staging/{$uploadId}/original",
                'client_filename' => 'cross-tenant.jpg',
                'upload_method' => 'single',
                'idempotency_key' => "cross-{$uploadId}",
                'request_fingerprint' => hash('sha256', $uploadId),
                'correlation_id' => (string) Str::uuid(),
                'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function test_people_proposals_and_account_links_use_the_standard_class_c_tenant_boundary(): void
    {
        [$ownerId, $firstFamily] = $this->createOwnedFamily('first-people-rls');
        [, $secondFamily] = $this->createOwnedFamily('second-people-rls');
        $firstPerson = (string) Str::ulid();
        $secondPerson = (string) Str::ulid();
        $now = now();
        $this->admin->table('people')->insert([
            [
                'id' => $firstPerson,
                'family_space_id' => $firstFamily,
                'preferred_name' => 'First Person',
                'identity_status' => 'confirmed',
                'birth_date_precision' => 'unknown',
                'is_deceased' => false,
                'death_date_precision' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'id' => $secondPerson,
                'family_space_id' => $secondFamily,
                'preferred_name' => 'Second Person',
                'identity_status' => 'confirmed',
                'birth_date_precision' => 'unknown',
                'is_deceased' => false,
                'death_date_precision' => 'unknown',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $this->assertSame([], DB::table('people')->pluck('preferred_name')->all());

        DB::beginTransaction();
        $context = app(DatabaseTenantContext::class);
        $context->establishUser($ownerId);
        $context->establishFamilySpace($firstFamily);
        $this->assertSame(['First Person'], DB::table('people')->pluck('preferred_name')->all());
        DB::table('person_detail_proposals')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'person_id' => $firstPerson,
            'changes' => '{}',
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertSame(1, DB::table('person_detail_proposals')->count());
        DB::table('person_account_links')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'person_id' => $firstPerson,
            'user_id' => $ownerId,
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('person_account_claims')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'person_id' => $firstPerson,
            'user_id' => $ownerId,
            'status' => 'rejected',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertSame(1, DB::table('person_account_links')->count());
        $this->assertSame(1, DB::table('person_account_claims')->count());
        DB::rollBack();

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('people')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'preferred_name' => 'Cross-tenant Person',
                'identity_status' => 'confirmed',
                'birth_date_precision' => 'unknown',
                'is_deceased' => false,
                'death_date_precision' => 'unknown',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $firstPerson): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('people')->where('id', $firstPerson)->update(['family_space_id' => $secondFamily]);
        });

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $secondPerson, $ownerId): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_detail_proposals')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'person_id' => $secondPerson,
                'changes' => '{}',
                'status' => 'pending',
                'proposed_by' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $secondPerson, $ownerId): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_account_links')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'person_id' => $secondPerson,
                'user_id' => $ownerId,
                'created_by' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $secondPerson, $ownerId): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_account_claims')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'person_id' => $secondPerson,
                'user_id' => $ownerId,
                'status' => 'pending',
                'proposed_by' => $ownerId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_relationship_and_circle_tables_use_the_standard_class_c_tenant_boundary(): void
    {
        [$ownerId, $firstFamily] = $this->createOwnedFamily('first-relationship-rls');
        [, $secondFamily] = $this->createOwnedFamily('second-relationship-rls');
        $firstPerson = $this->createPerson($firstFamily, 'First Person');
        $relatedPerson = $this->createPerson($firstFamily, 'Related Person');
        $secondPerson = $this->createPerson($secondFamily, 'Second Person');
        $now = now();

        foreach (['person_relationships', 'relationship_proposals', 'family_circles', 'family_circle_people'] as $table) {
            $this->assertSame(0, DB::table($table)->count());
        }

        DB::beginTransaction();
        app(DatabaseTenantContext::class)->establishUser($ownerId);
        app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
        $relationshipId = (string) Str::ulid();
        $circleId = (string) Str::ulid();
        DB::table('person_relationships')->insert([
            'id' => $relationshipId,
            'family_space_id' => $firstFamily,
            'subject_person_id' => $firstPerson,
            'related_person_id' => $relatedPerson,
            'type' => 'parent_of',
            'status' => 'confirmed',
            'created_by' => $ownerId,
            'updated_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('relationship_proposals')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'relationship_id' => $relationshipId,
            'subject_person_id' => $firstPerson,
            'related_person_id' => $relatedPerson,
            'action' => 'dispute',
            'type' => 'parent_of',
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('family_circles')->insert([
            'id' => $circleId,
            'family_space_id' => $firstFamily,
            'name' => 'Visible Circle',
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('family_circle_people')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'family_circle_id' => $circleId,
            'person_id' => $firstPerson,
            'added_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach (['person_relationships', 'relationship_proposals', 'family_circles', 'family_circle_people'] as $table) {
            $this->assertSame(1, DB::table($table)->count());
        }
        DB::rollBack();

        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $secondPerson, $ownerId, $now): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_relationships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'subject_person_id' => $secondPerson,
                'related_person_id' => $secondPerson,
                'type' => 'sibling_of',
                'status' => 'confirmed',
                'created_by' => $ownerId,
                'updated_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        $this->assertRlsRejects(function () use ($firstFamily, $secondFamily, $ownerId, $now): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('family_circles')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'name' => 'Cross-tenant Circle',
                'created_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function test_merge_ledger_and_proposals_are_tenant_isolated_and_enforce_active_uniqueness(): void
    {
        [$ownerId, $firstFamily] = $this->createOwnedFamily('first-merge-rls');
        [, $secondFamily] = $this->createOwnedFamily('second-merge-rls');
        $survivor = $this->createPerson($firstFamily, 'Survivor');
        $absorbed = $this->createPerson($firstFamily, 'Absorbed');
        $foreignSurvivor = $this->createPerson($secondFamily, 'Foreign survivor');
        $foreignAbsorbed = $this->createPerson($secondFamily, 'Foreign absorbed');
        $mergeId = (string) Str::ulid();
        $now = now();

        $this->assertSame(0, DB::table('person_merges')->count());
        $this->assertSame(0, DB::table('person_merge_proposals')->count());

        DB::beginTransaction();
        app(DatabaseTenantContext::class)->establishUser($ownerId);
        app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
        DB::table('person_merges')->insert([
            'id' => $mergeId,
            'family_space_id' => $firstFamily,
            'survivor_person_id' => $survivor,
            'absorbed_person_id' => $absorbed,
            'status' => 'active',
            'provenance' => '{}',
            'merged_by' => $ownerId,
            'merged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('person_merge_proposals')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'survivor_person_id' => $survivor,
            'absorbed_person_id' => $absorbed,
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertSame(1, DB::table('person_merges')->count());
        $this->assertSame(1, DB::table('person_merge_proposals')->count());
        DB::rollBack();

        $this->assertRlsRejects(function () use (
            $firstFamily,
            $secondFamily,
            $foreignSurvivor,
            $foreignAbsorbed,
            $ownerId,
            $now,
        ): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_merges')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'survivor_person_id' => $foreignSurvivor,
                'absorbed_person_id' => $foreignAbsorbed,
                'status' => 'active',
                'provenance' => '{}',
                'merged_by' => $ownerId,
                'merged_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
        $this->assertRlsRejects(function () use (
            $firstFamily,
            $secondFamily,
            $foreignSurvivor,
            $foreignAbsorbed,
            $ownerId,
            $now,
        ): void {
            app(DatabaseTenantContext::class)->establishFamilySpace($firstFamily);
            DB::table('person_merge_proposals')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $secondFamily,
                'survivor_person_id' => $foreignSurvivor,
                'absorbed_person_id' => $foreignAbsorbed,
                'status' => 'pending',
                'proposed_by' => $ownerId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });

        $this->admin->table('person_merges')->insert([
            'id' => $mergeId,
            'family_space_id' => $firstFamily,
            'survivor_person_id' => $survivor,
            'absorbed_person_id' => $absorbed,
            'status' => 'active',
            'provenance' => '{}',
            'merged_by' => $ownerId,
            'merged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_merges')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $firstFamily,
            'survivor_person_id' => $survivor,
            'absorbed_person_id' => $absorbed,
            'status' => 'manual_correction_required',
            'provenance' => '{}',
            'merged_by' => $ownerId,
            'merged_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $proposal = [
            'family_space_id' => $firstFamily,
            'survivor_person_id' => $survivor,
            'absorbed_person_id' => $absorbed,
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $this->admin->table('person_merge_proposals')->insert([
            'id' => (string) Str::ulid(),
            ...$proposal,
        ]);
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_merge_proposals')->insert([
            'id' => (string) Str::ulid(),
            ...$proposal,
        ]));
    }

    public function test_account_links_and_pending_claims_enforce_one_to_one_cardinality(): void
    {
        [$ownerId, $familySpace] = $this->createOwnedFamily('link-cardinality');
        $secondUserId = $this->createUser('second-link-user@example.test');
        $this->addMembership($familySpace, $secondUserId, FamilySpaceRole::Member);
        $firstPerson = $this->createPerson($familySpace, 'First linked Person');
        $secondPerson = $this->createPerson($familySpace, 'Second linked Person');
        $now = now();

        $this->admin->table('person_account_links')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $firstPerson,
            'user_id' => $ownerId,
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_account_links')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $firstPerson,
            'user_id' => $secondUserId,
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_account_links')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $secondPerson,
            'user_id' => $ownerId,
            'created_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $this->admin->table('person_account_claims')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $firstPerson,
            'user_id' => $ownerId,
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_account_claims')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $firstPerson,
            'user_id' => $secondUserId,
            'status' => 'pending',
            'proposed_by' => $secondUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
        $this->assertUniqueConstraintRejects(fn () => $this->admin->table('person_account_claims')->insert([
            'id' => (string) Str::ulid(),
            'family_space_id' => $familySpace,
            'person_id' => $secondPerson,
            'user_id' => $ownerId,
            'status' => 'pending',
            'proposed_by' => $ownerId,
            'created_at' => $now,
            'updated_at' => $now,
        ]));
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

    public function test_one_tenant_context_cannot_mutate_another_tenant_for_a_multi_space_user(): void
    {
        [$ownerId, $firstFamily] = $this->createOwnedFamily('first-managed-family');
        [, $secondFamily] = $this->createOwnedFamily('second-managed-family');
        $secondMembership = $this->addMembership($secondFamily, $ownerId, FamilySpaceRole::Administrator);

        DB::beginTransaction();
        $context = app(DatabaseTenantContext::class);
        $context->establishUser($ownerId);
        $context->establishFamilySpace($firstFamily, canManageMemberships: true);
        $this->assertSame(0, DB::table('family_spaces')
            ->where('id', $secondFamily)
            ->update(['name' => 'Cross-tenant registry write']));
        $this->assertSame(0, DB::table('family_space_memberships')
            ->where('id', $secondMembership)
            ->update(['role' => FamilySpaceRole::Guest->value]));
        DB::rollBack();

        $this->assertRlsRejects(function () use ($firstFamily, $ownerId, $secondFamily): void {
            $context = app(DatabaseTenantContext::class);
            $context->establishUser($ownerId);
            $context->establishFamilySpace($firstFamily, canManageMemberships: true);
            DB::table('audit_events')->insert([
                'family_space_id' => $secondFamily,
                'actor_user_id' => $ownerId,
                'action' => 'cross_tenant.rejected',
                'subject_type' => 'test',
                'subject_id' => $secondFamily,
                'correlation_id' => (string) Str::uuid(),
                'traceparent' => sprintf('00-%s-%s-01', bin2hex(random_bytes(16)), bin2hex(random_bytes(8))),
                'metadata' => '{}',
                'created_at' => now(),
            ]);
        });

        $this->assertSame('Second Managed Family', $this->admin->table('family_spaces')
            ->where('id', $secondFamily)
            ->value('name'));
        $this->assertSame(FamilySpaceRole::Administrator->value, $this->admin->table('family_space_memberships')
            ->where('id', $secondMembership)
            ->value('role'));
        $this->assertSame(0, $this->admin->table('audit_events')
            ->where('action', 'cross_tenant.rejected')
            ->count());
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

    public function test_membership_reactivation_is_race_safe_under_concurrent_acceptance(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('stream_socket_pair')) {
            $this->markTestSkipped('The PostgreSQL concurrency test requires pcntl and stream sockets.');
        }

        [$ownerId, $familySpaceId] = $this->createOwnedFamily('concurrent-acceptance-family');
        $inviteeId = $this->createUser('concurrent-invitee@example.test');
        $membershipId = $this->addMembership($familySpaceId, $inviteeId, FamilySpaceRole::Guest);
        $this->admin->table('family_space_memberships')->where('id', $membershipId)->update([
            'state' => MembershipState::Removed->value,
            'removed_at' => now(),
        ]);

        $invitationIds = [];
        foreach ([FamilySpaceRole::Member, FamilySpaceRole::Contributor] as $index => $role) {
            $invitationIds[] = (int) $this->admin->table('invitations')->insertGetId([
                'family_space_id' => $familySpaceId,
                'email' => "concurrent-{$index}@example.test",
                'role' => $role->value,
                'invited_by' => $ownerId,
                'status' => 'pending',
                'expires_at' => now()->addDay(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::disconnect();
        DB::disconnect('pgsql_admin');

        $children = [];
        foreach ($invitationIds as $invitationId) {
            $sockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $this->assertNotFalse($sockets);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                fclose($sockets[0]);
                fread($sockets[1], 1);
                DB::purge();
                DB::purge('pgsql_admin');

                try {
                    $outcome = DB::transaction(function () use ($familySpaceId, $invitationId, $inviteeId): string {
                        $context = app(DatabaseTenantContext::class);
                        $context->establishUser($inviteeId);
                        $context->establishFamilySpace(
                            $familySpaceId,
                            authoritativeOperation: 'invitation_acceptance',
                        );
                        $result = app(MembershipInvitationAcceptor::class)->accept(
                            Invitation::query()->findOrFail($invitationId),
                            User::query()->findOrFail($inviteeId),
                        );

                        return $result === null ? 'rejected' : 'reactivated';
                    });
                } catch (\Throwable $exception) {
                    $outcome = 'error:'.get_class($exception).':'.$exception->getMessage();
                }

                fwrite($sockets[1], $outcome);
                fclose($sockets[1]);
                exit(0);
            }

            fclose($sockets[1]);
            $children[] = ['pid' => $pid, 'socket' => $sockets[0]];
        }

        foreach ($children as $child) {
            fwrite($child['socket'], '1');
        }

        $outcomes = [];
        foreach ($children as $child) {
            $outcomes[] = stream_get_contents($child['socket']);
            fclose($child['socket']);
            pcntl_waitpid($child['pid'], $status);
            $this->assertTrue(pcntl_wifexited($status));
            $this->assertSame(0, pcntl_wexitstatus($status));
        }

        DB::purge();
        DB::purge('pgsql_admin');
        $this->admin = DB::connection('pgsql_admin');

        $this->assertEqualsCanonicalizing(['reactivated', 'rejected'], $outcomes);
        $memberships = $this->admin->table('family_space_memberships')
            ->where('family_space_id', $familySpaceId)
            ->where('user_id', $inviteeId)
            ->get();
        $this->assertCount(1, $memberships);
        $this->assertSame(MembershipState::Active->value, $memberships->sole()->state);
    }

    public function test_due_deletion_dispatch_and_teardown_preserve_context_under_rls(): void
    {
        [$ownerId, $familySpaceId] = $this->createOwnedFamily('due-deletion-family');
        $this->admin->table('family_spaces')->where('id', $familySpaceId)->update([
            'status' => 'deletion_requested',
            'deletion_requested_at' => now()->subDays(15),
            'deletion_requested_by' => $ownerId,
            'scheduled_deletion_at' => now()->subMinute(),
        ]);
        Queue::fake();

        $this->artisan('fambam:dispatch-due-family-space-deletions')->assertSuccessful();

        $job = null;
        Queue::assertPushed(DeleteFamilySpace::class, function (DeleteFamilySpace $queued) use (&$job, $familySpaceId, $ownerId): bool {
            $job = $queued;

            return $queued->context['family_space_id'] === $familySpaceId
                && $queued->context['actor_user_id'] === $ownerId
                && preg_match('/^[0-9a-f-]{36}$/', $queued->context['correlation_id']) === 1
                && preg_match('/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/', $queued->context['traceparent']) === 1;
        });
        $this->assertInstanceOf(DeleteFamilySpace::class, $job);
        $job->handle(app(FamilySpaceDeletionManager::class));

        $this->assertSame('deleted', $this->admin->table('family_spaces')->where('id', $familySpaceId)->value('status'));
        $this->assertSame(0, $this->admin->table('family_space_memberships')
            ->where('family_space_id', $familySpaceId)
            ->where('state', 'active')
            ->count());
        $audit = $this->admin->table('audit_events')->where('action', 'family_space.deleted')->sole();
        $this->assertSame($familySpaceId, $audit->family_space_id);
        $this->assertSame($job->context['correlation_id'], $audit->correlation_id);
        $this->assertSame($job->context['traceparent'], $audit->traceparent);

        $job->handle(app(FamilySpaceDeletionManager::class));
        $this->assertSame(1, $this->admin->table('audit_events')->where('action', 'family_space.deleted')->count());
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

    private function createPerson(string $familySpaceId, string $name): string
    {
        $personId = (string) Str::ulid();
        $this->admin->table('people')->insert([
            'id' => $personId,
            'family_space_id' => $familySpaceId,
            'preferred_name' => $name,
            'identity_status' => 'confirmed',
            'birth_date_precision' => 'unknown',
            'is_deceased' => false,
            'death_date_precision' => 'unknown',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $personId;
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

    /** @param callable(): mixed $operation */
    private function assertUniqueConstraintRejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL unexpectedly accepted a duplicate one-to-one association.');
        } catch (QueryException $exception) {
            $this->assertSame('23505', $exception->errorInfo[0]);
        }
    }

    private function quotedIdentifier(string $identifier): string
    {
        $this->assertMatchesRegularExpression('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier);

        return '"'.$identifier.'"';
    }
}
