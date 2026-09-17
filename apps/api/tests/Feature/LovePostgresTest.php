<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LovePostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Love constraints require administrative and runtime PostgreSQL connections.');
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

    public function test_typed_targets_uniqueness_tenant_integrity_and_forced_rls(): void
    {
        [$user, $family, $album, $event, $story] = $this->fixture('love-pg-one');
        [, $otherFamily, $otherAlbum] = $this->fixture('love-pg-two');
        $reaction = ['id' => (string) Str::ulid(), 'family_space_id' => $family,
            'album_id' => $album, 'user_id' => $user, 'reaction' => 'love'];
        $this->admin->table('reactions')->insert($reaction);
        $this->rejects(fn () => $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(),
        ]));
        $this->rejects(fn () => $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'event_id' => $event,
        ]));
        $this->rejects(fn () => $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'album_id' => null,
        ]));
        $this->rejects(fn () => $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'reaction' => 'smile', 'event_id' => $event,
        ]));
        $this->rejects(fn () => $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'album_id' => $otherAlbum,
        ]));
        $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'album_id' => null, 'event_id' => $event,
        ]);
        $this->admin->table('reactions')->insert([
            ...$reaction, 'id' => (string) Str::ulid(), 'album_id' => null, 'story_id' => $story,
        ]);
        $group = ['id' => (string) Str::ulid(), 'family_space_id' => $family, 'album_id' => $album];
        $this->admin->table('love_notification_groups')->insert($group);
        $this->rejects(fn () => $this->admin->table('love_notification_groups')->insert([
            ...$group, 'id' => (string) Str::ulid(), 'event_id' => $event,
        ]));
        $this->rejects(fn () => $this->admin->table('love_notification_groups')->insert([
            ...$group, 'id' => (string) Str::ulid(), 'album_id' => $otherAlbum,
        ]));
        $actor = ['id' => (string) Str::ulid(), 'family_space_id' => $family,
            'group_id' => $group['id'], 'actor_user_id' => $user];
        $this->admin->table('love_notification_group_actors')->insert($actor);
        $this->rejects(fn () => $this->admin->table('love_notification_group_actors')->insert([
            ...$actor, 'id' => (string) Str::ulid(),
        ]));
        $this->rejects(fn () => $this->admin->table('love_notification_group_actors')->insert([
            ...$actor, 'id' => (string) Str::ulid(), 'family_space_id' => $otherFamily,
        ]));
        foreach (['reactions', 'love_notification_groups', 'love_notification_group_actors'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
            $this->assertSame($table === 'reactions' ? 3 : 1, $this->tenantCount($table, $user, $family));
            $this->assertSame(0, $this->tenantCount($table, $user, $otherFamily));
        }
    }

    /** @return array{int,string,string,string,string} */
    private function fixture(string $slug): array
    {
        $user = (int) $this->admin->table('users')->insertGetId(['name' => $slug,
            'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now()]);
        $family = (string) Str::ulid();
        $album = (string) Str::ulid();
        $event = (string) Str::ulid();
        $story = (string) Str::ulid();
        $this->admin->transaction(function () use ($slug, $user, $family, $album, $event, $story): void {
            $this->admin->table('family_spaces')->insert(['id' => $family, 'slug' => $slug,
                'name' => $slug, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('family_space_memberships')->insert(['id' => (string) Str::ulid(),
                'family_space_id' => $family, 'user_id' => $user, 'role' => 'owner',
                'state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('albums')->insert(['id' => $album, 'family_space_id' => $family,
                'created_by' => $user, 'name' => 'Album', 'visibility' => 'family_space',
                'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('events')->insert(['id' => $event, 'family_space_id' => $family,
                'created_by' => $user, 'name' => 'Event', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now()]);
            $this->admin->table('stories')->insert(['id' => $story, 'family_space_id' => $family,
                'author_id' => $user, 'album_id' => $album,
                'body' => json_encode(['schema_version' => 1, 'blocks' => []]),
                'body_plain_text' => '', 'created_at' => now(), 'updated_at' => now()]);
        });

        return [$user, $family, $album, $event, $story];
    }

    private function tenantCount(string $table, int $user, string $family): int
    {
        return DB::transaction(function () use ($table, $user, $family): int {
            app(DatabaseTenantContext::class)->establishUser($user);
            app(DatabaseTenantContext::class)->establishFamilySpace($family);

            return DB::table($table)->count();
        });
    }

    private function rejects(callable $operation): void
    {
        try {
            $operation();
            $this->fail('PostgreSQL accepted an invalid Love row.');
        } catch (QueryException) {
        }
    }
}
