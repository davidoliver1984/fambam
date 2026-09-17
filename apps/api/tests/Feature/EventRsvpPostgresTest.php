<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EventRsvpPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Event RSVP integrity requires administrative and runtime PostgreSQL connections.');
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

    public function test_rsvp_check_event_people_composite_fks_notification_subject_and_rls(): void
    {
        [$userId, $familyId, $eventId, $personId, $membershipId] = $this->fixture('rsvp-pg-one');
        [, $otherFamilyId, $otherEventId, $otherPersonId] = $this->fixture('rsvp-pg-two');
        $admissionId = (string) Str::ulid();
        $this->admin->table('event_admissions')->insert(['id' => $admissionId,
            'family_space_id' => $familyId, 'event_id' => $eventId,
            'family_space_membership_id' => $membershipId, 'admitted_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('pending', $this->admin->table('event_admissions')->where('id', $admissionId)->value('rsvp_status'));
        $this->rejects(fn () => $this->admin->table('event_admissions')->where('id', $admissionId)
            ->update(['rsvp_status' => 'maybe']));
        $this->admin->table('event_people')->insert(['id' => (string) Str::ulid(),
            'family_space_id' => $familyId, 'event_id' => $eventId,
            'person_id' => $personId, 'added_by' => $userId]);
        $this->rejects(fn () => $this->admin->table('event_people')->insert(['id' => (string) Str::ulid(),
            'family_space_id' => $familyId, 'event_id' => $eventId, 'person_id' => $otherPersonId]));
        $this->rejects(fn () => $this->admin->table('event_people')->insert(['id' => (string) Str::ulid(),
            'family_space_id' => $familyId, 'event_id' => $otherEventId, 'person_id' => $personId]));
        $notification = ['id' => (string) Str::ulid(), 'family_space_id' => $familyId,
            'recipient_user_id' => $userId, 'category' => 'attendance',
            'source_action_id' => (string) Str::ulid(), 'event_id' => $eventId,
            'created_at' => now(), 'updated_at' => now()];
        $this->admin->table('notifications')->insert($notification);
        $this->rejects(fn () => $this->admin->table('notifications')->insert([
            ...$notification, 'id' => (string) Str::ulid(), 'source_action_id' => (string) Str::ulid(),
            'event_id' => null,
        ]));
        $this->rejects(fn () => $this->admin->table('notifications')->insert([
            ...$notification, 'id' => (string) Str::ulid(), 'source_action_id' => (string) Str::ulid(),
            'event_id' => $otherEventId,
        ]));
        $this->admin->table('notification_deliveries')->insert([
            ...$notification, 'id' => (string) Str::ulid(), 'channel' => 'in_app',
        ]);
        $this->rejects(fn () => $this->admin->table('notification_deliveries')->insert([
            ...$notification, 'id' => (string) Str::ulid(), 'source_action_id' => (string) Str::ulid(),
            'channel' => 'in_app', 'event_id' => null,
        ]));
        foreach (['event_people', 'event_admissions'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
        }
        $visible = DB::transaction(function () use ($userId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('event_people')->count();
        });
        $hidden = DB::transaction(function () use ($userId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($userId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('event_people')->count();
        });
        $this->assertSame(1, $visible);
        $this->assertSame(0, $hidden);
    }

    /** @return array{int, string, string, string, string} */
    private function fixture(string $slug): array
    {
        $userId = (int) $this->admin->table('users')->insertGetId(['name' => $slug,
            'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
        $familyId = (string) Str::ulid();
        $eventId = (string) Str::ulid();
        $personId = (string) Str::ulid();
        $membershipId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $slug, $membershipId, $userId): void {
            $this->admin->table('family_spaces')->insert(['id' => $familyId, 'slug' => $slug,
                'name' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('family_space_memberships')->insert(['id' => $membershipId,
                'family_space_id' => $familyId, 'user_id' => $userId, 'role' => 'owner',
                'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->admin->table('events')->insert(['id' => $eventId,
            'family_space_id' => $familyId, 'created_by' => $userId,
            'name' => $slug, 'created_at' => now(), 'updated_at' => now()]);
        $this->admin->table('people')->insert(['id' => $personId,
            'family_space_id' => $familyId, 'preferred_name' => $slug,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        return [$userId, $familyId, $eventId, $personId, $membershipId];
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('The database accepted an invalid Event RSVP relationship or subject.');
        } catch (QueryException) {
        }
    }
}
