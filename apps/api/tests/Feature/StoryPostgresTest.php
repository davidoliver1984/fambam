<?php

namespace Tests\Feature;

use App\Tenancy\DatabaseTenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class StoryPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Story integrity tests require runtime and administrative PostgreSQL connections.');
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

    public function test_story_integrity_tenancy_and_rls_are_database_enforced(): void
    {
        [$ownerId, $familyId, $photoId, $personId] = $this->familyFixture('story-pg-one');
        [, $otherFamilyId, , $otherPersonId] = $this->familyFixture('story-pg-two');
        $storyId = (string) Str::ulid();
        $document = json_encode(['schema_version' => 1, 'blocks' => []], JSON_THROW_ON_ERROR);
        $this->admin->table('stories')->insert([
            'id' => $storyId, 'family_space_id' => $familyId, 'author_id' => $ownerId,
            'photo_id' => $photoId, 'body' => $document, 'body_plain_text' => 'A PostgreSQL Story',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $stored = $this->admin->selectOne('SELECT search_vector::text AS vector FROM stories WHERE id = ?', [$storyId]);
        $this->assertStringContainsString('postgresql', $stored->vector);

        foreach ([['person_id' => null, 'photo_id' => null], ['person_id' => $personId, 'photo_id' => $photoId]] as $subjects) {
            try {
                $this->admin->table('stories')->insert([
                    'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'author_id' => $ownerId,
                    'body' => $document, 'body_plain_text' => 'Invalid', 'created_at' => now(), 'updated_at' => now(),
                    ...$subjects,
                ]);
                $this->fail('An invalid Story subject combination was accepted.');
            } catch (QueryException) {
            }
        }

        try {
            $this->admin->table('story_person_mentions')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'story_id' => $storyId,
                'mention_id' => (string) Str::ulid(), 'person_id' => $otherPersonId,
                'historical_label_snapshot' => 'Other tenant', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('A cross-tenant Story mention was accepted.');
        } catch (QueryException) {
        }

        $visible = DB::transaction(function () use ($ownerId, $familyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($familyId);

            return DB::table('stories')->count();
        });
        $hidden = DB::transaction(function () use ($ownerId, $otherFamilyId): int {
            app(DatabaseTenantContext::class)->establishUser($ownerId);
            app(DatabaseTenantContext::class)->establishFamilySpace($otherFamilyId);

            return DB::table('stories')->count();
        });
        $this->assertSame(1, $visible);
        $this->assertSame(0, $hidden);

        foreach (['stories', 'story_revisions', 'story_comments', 'story_person_mentions', 'story_comment_person_mentions',
            'person_biography_mentions', 'album_description_mentions', 'event_description_mentions',
            'photo_comment_person_mentions'] as $table) {
            $flags = $this->admin->selectOne('SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = ?', [$table]);
            $this->assertTrue($flags->relrowsecurity);
            $this->assertTrue($flags->relforcerowsecurity);
        }
    }

    public function test_story_consumer_foreign_keys_and_comment_notification_shape_are_database_enforced(): void
    {
        [$ownerId, $familyId, $photoId] = $this->familyFixture('story-consumer-pg');
        $storyId = (string) Str::ulid();
        $commentId = (string) Str::ulid();
        $document = json_encode(['schema_version' => 1, 'blocks' => []], JSON_THROW_ON_ERROR);
        $this->admin->table('stories')->insert([
            'id' => $storyId, 'family_space_id' => $familyId, 'author_id' => $ownerId,
            'photo_id' => $photoId, 'body' => $document, 'body_plain_text' => 'Consumer Story',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('story_comments')->insert([
            'id' => $commentId, 'family_space_id' => $familyId, 'story_id' => $storyId,
            'author_id' => $ownerId, 'body' => $document, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('notifications')->insert([
            'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'recipient_user_id' => $ownerId,
            'category' => 'comment', 'source_action_id' => $commentId, 'story_id' => $storyId,
            'story_comment_id' => $commentId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->admin->table('notifications')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'recipient_user_id' => $ownerId,
                'category' => 'comment', 'source_action_id' => (string) Str::ulid(), 'story_id' => $storyId,
                'story_comment_id' => $commentId, 'photo_id' => $photoId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->fail('A mixed Photo-comment and Story-comment notification shape was accepted.');
        } catch (QueryException) {
        }

        foreach (['family_activities_story_family_foreign', 'notifications_story_family_foreign', 'notification_deliveries_story_family_foreign'] as $constraint) {
            $target = $this->admin->selectOne(<<<'SQL'
SELECT target.relname AS target
FROM pg_constraint constraint_row
JOIN pg_class target ON target.oid = constraint_row.confrelid
WHERE constraint_row.conname = ?
SQL, [$constraint]);
            $this->assertSame('stories', $target->target);
        }

        $legacyTables = $this->admin->table('pg_tables')
            ->where('schemaname', 'public')
            ->whereIn('tablename', ['photo_stories', 'photo_story_revisions'])
            ->pluck('tablename')
            ->all();
        $this->assertSame([], $legacyTables);
    }

    /** @return array{int, string, string, string} */
    private function familyFixture(string $slug): array
    {
        $ownerId = (int) $this->admin->table('users')->insertGetId([
            'name' => $slug, 'email' => "{$slug}@example.test", 'password' => 'not-used',
            'timezone' => 'Europe/London', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $familyId = (string) Str::ulid();
        $uploadId = (string) Str::ulid();
        $photoId = (string) Str::ulid();
        $personId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $ownerId, $slug): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId, 'slug' => $slug, 'name' => $slug, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(), 'family_space_id' => $familyId, 'user_id' => $ownerId,
                'role' => 'owner', 'state' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        $this->admin->table('media_uploads')->insert([
            'id' => $uploadId, 'family_space_id' => $familyId, 'user_id' => $ownerId, 'state' => 'ready',
            'staging_object_key' => "families/{$familyId}/media-staging/{$uploadId}/original",
            'client_filename' => 'story.jpg', 'idempotency_key' => "story-{$uploadId}",
            'request_fingerprint' => hash('sha256', $uploadId), 'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('photos')->insert([
            'id' => $photoId, 'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
            'created_by' => $ownerId, 'visibility' => 'family_space', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->admin->table('people')->insert([
            'id' => $personId, 'family_space_id' => $familyId, 'preferred_name' => $slug,
            'alternate_names' => '[]', 'identity_status' => 'confirmed', 'birth_date_precision' => 'unknown',
            'is_deceased' => false, 'death_date_precision' => 'unknown', 'recognition_allowed' => false,
            'created_by' => $ownerId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$ownerId, $familyId, $photoId, $personId];
    }
}
