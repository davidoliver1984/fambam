<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventTagPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Event tag integrity requires administrative and runtime PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_event_tag_rejects_cross_family_rows_and_forces_rls(): void
    {
        [$userId, $familyId, $eventId, $tagId] = $this->fixture('event-tag-pg-one');
        [, $otherFamilyId, $otherEventId, $otherTagId] = $this->fixture('event-tag-pg-two');

        $this->admin->table('event_tag')->insert(['family_space_id' => $familyId,
            'event_id' => $eventId, 'tag_id' => $tagId, 'added_by' => $userId]);
        $this->rejects(fn () => $this->admin->table('event_tag')->insert([
            'family_space_id' => $familyId, 'event_id' => $eventId, 'tag_id' => $otherTagId,
        ]));
        $this->rejects(fn () => $this->admin->table('event_tag')->insert([
            'family_space_id' => $familyId, 'event_id' => $otherEventId, 'tag_id' => $tagId,
        ]));

        $flags = $this->admin->selectOne(
            'SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?',
            ['event_tag'],
        );
        $this->assertTrue($flags->relrowsecurity);
        $this->assertTrue($flags->relforcerowsecurity);

        $visible = DB::transaction(function () use ($userId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('event_tag')->count();
        });
        $hidden = DB::transaction(function () use ($userId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('event_tag')->count();
        });
        $this->assertSame(1, $visible);
        $this->assertSame(0, $hidden);
    }

    /** @return array{int, string, string, string} */
    private function fixture(string $slug): array
    {
        $userId = (int) $this->admin->table('users')->insertGetId([
            'name' => $slug, 'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $familyId = (string) Str::ulid();
        $eventId = (string) Str::ulid();
        $tagId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $slug, $userId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId, 'slug' => $slug, 'name' => $slug, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
                'user_id' => $userId, 'role' => 'owner', 'state' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->admin->table('events')->insert([
            'id' => $eventId, 'family_space_id' => $familyId, 'created_by' => $userId,
            'name' => $slug, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('tags')->insert([
            'id' => $tagId, 'family_space_id' => $familyId, 'label' => $slug,
            'normalized_label' => $slug, 'created_by' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$userId, $familyId, $eventId, $tagId];
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted a cross-family Event tag relationship.');
        } catch (QueryException) {
        }
    }
}
