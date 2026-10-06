<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoIndexPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Photo index RLS tests require administrative and runtime PostgreSQL connections.');
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

    public function test_forced_rls_multi_page_search_sort_without_album_and_membership_flow(): void
    {
        [$viewerId, $creatorId, $familyId] = $this->family('photo-index-pg');
        [, , $otherFamilyId] = $this->family('photo-index-pg-other', $viewerId, $creatorId);
        $photoIds = $this->photos($familyId, $creatorId, 73, 'Needle archive');
        $this->photos($familyId, $creatorId, 1, 'Needle private', 'private');
        $this->photos($otherFamilyId, $creatorId, 1, 'Needle foreign');

        $hiddenAlbum = $this->album($familyId, $creatorId, 'Hidden membership', 'private');
        $albumMembers = array_values(array_filter(
            $photoIds,
            fn (string $id, int $index): bool => $index % 5 === 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        foreach ($albumMembers as $position => $photoId) {
            $this->admin->table('album_photos')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familyId,
                'album_id' => $hiddenAlbum,
                'photo_id' => $photoId,
                'position' => $position + 1,
                'added_by' => $creatorId,
                'created_at' => now(),
            ]);
        }

        $viewer = User::query()->findOrFail($viewerId);
        foreach (['newest', 'oldest', 'recently_added'] as $sort) {
            $ids = $this->collectPages($viewer, "/api/families/photo-index-pg/photos?limit=20&sort={$sort}");
            $this->assertCount(73, $ids, $sort);
            $this->assertCount(73, array_unique($ids), $sort);
            $this->assertSame($this->expectedOrder($familyId, $sort), $ids, $sort);
        }

        $searchIds = $this->collectPages(
            $viewer,
            '/api/families/photo-index-pg/photos?limit=17&q=Needle&sort=newest',
        );
        $this->assertCount(73, $searchIds);
        $this->assertNotContains(
            $this->admin->table('photos')->where('caption', 'Needle private')->value('id'),
            $searchIds,
        );
        $this->assertNotContains(
            $this->admin->table('photos')->where('caption', 'Needle foreign')->value('id'),
            $searchIds,
        );

        $withoutAlbum = $this->collectPages(
            $viewer,
            '/api/families/photo-index-pg/photos?limit=13&q=Needle&sort=oldest&without_album=1',
        );
        $this->assertCount(73 - count($albumMembers), $withoutAlbum);
        $this->assertSame([], array_values(array_intersect($albumMembers, $withoutAlbum)));

        $first = $this->actingAs($viewer)->getJson(
            '/api/families/photo-index-pg/photos?limit=1&q=Needle&sort=newest',
        )->assertOk();
        $cursor = urlencode((string) $first->json('data.next_cursor'));
        $this->actingAs($viewer)->getJson(
            "/api/families/photo-index-pg-other/photos?limit=1&q=Needle&sort=newest&cursor={$cursor}",
        )->assertUnprocessable()->assertJsonValidationErrors('cursor');

        $pickerAlbum = $this->album($familyId, $viewerId, 'Picker target');
        $pickerPhoto = $photoIds[1];
        $path = "/api/families/photo-index-pg/albums/{$pickerAlbum}/photos";
        $this->actingAs($viewer)->postJson($path, ['photo_id' => $pickerPhoto])->assertCreated();
        $this->actingAs($viewer)->postJson($path, ['photo_id' => $pickerPhoto])->assertCreated();
        $this->assertSame(1, $this->admin->table('album_photos')
            ->where('album_id', $pickerAlbum)->where('photo_id', $pickerPhoto)->count());
        $this->actingAs($viewer)
            ->getJson("/api/families/photo-index-pg/photos/{$pickerPhoto}/album-history")
            ->assertOk()
            ->assertJsonPath('data.0.album.id', $pickerAlbum)
            ->assertJsonPath('data.0.album.name', 'Picker target')
            ->assertJsonPath('data.0.is_current', true);
    }

    public function test_cursor_indexes_and_search_index_support_representative_plans(): void
    {
        [, $creatorId, $familyId] = $this->family('photo-index-plans');
        $photoIds = $this->photos($familyId, $creatorId, 2500, 'Archive memory');
        $needleIds = array_values(array_filter(
            $photoIds,
            fn (string $id, int $index): bool => $index % 125 === 0,
            ARRAY_FILTER_USE_BOTH,
        ));
        $this->admin->table('photos')->whereIn('id', $needleIds)->update(['caption' => 'Needle memory']);

        $indexes = $this->admin->table('pg_indexes')->where('schemaname', 'public')
            ->whereIn('indexname', [
                'photos_family_created_cursor_index',
                'photos_family_historical_date_cursor_index',
                'photos_search_vector_gin',
            ])->pluck('indexname')->sort()->values()->all();
        $this->assertSame([
            'photos_family_created_cursor_index',
            'photos_family_historical_date_cursor_index',
            'photos_search_vector_gin',
        ], $indexes);

        $plans = $this->admin->transaction(function () use ($familyId): array {
            $this->admin->statement('ANALYZE photos');
            $this->admin->statement('SET LOCAL enable_seqscan = off');

            return [
                $this->plan(<<<'SQL'
SELECT id FROM photos
WHERE family_space_id = ?
ORDER BY created_at DESC, id DESC
LIMIT 25
SQL, [$familyId]),
                $this->plan(<<<'SQL'
SELECT id FROM photos
WHERE family_space_id = ?
  AND search_vector @@ websearch_to_tsquery('simple'::regconfig, 'needle')
ORDER BY historical_date DESC, id DESC
LIMIT 25
SQL, [$familyId]),
            ];
        });
        $createdPlan = json_encode($plans[0], JSON_THROW_ON_ERROR);
        $searchPlan = json_encode($plans[1], JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('photos_family_created_cursor_index', $createdPlan);
        $this->assertStringContainsString('photos_search_vector_gin', $searchPlan);
        $this->assertLessThan(1000.0, (float) $plans[0][0]['Execution Time']);
        $this->assertLessThan(1000.0, (float) $plans[1][0]['Execution Time']);
    }

    /** @return array{int, int, string} */
    private function family(string $slug, ?int $viewerId = null, ?int $creatorId = null): array
    {
        $viewerId ??= $this->user("{$slug}-viewer");
        $creatorId ??= $this->user("{$slug}-creator");
        $familyId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familyId, $slug, $viewerId, $creatorId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familyId, 'slug' => $slug, 'name' => $slug, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ([[$viewerId, 'member'], [$creatorId, 'owner']] as [$userId, $role]) {
                $this->admin->table('family_space_memberships')->insert([
                    'id' => (string) Str::ulid(), 'family_space_id' => $familyId,
                    'user_id' => $userId, 'role' => $role, 'state' => 'active',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });

        return [$viewerId, $creatorId, $familyId];
    }

    private function user(string $name): int
    {
        return (int) $this->admin->table('users')->insertGetId([
            'name' => $name,
            'email' => "{$name}@example.test",
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function photos(
        string $familyId,
        int $creatorId,
        int $count,
        string $caption,
        string $visibility = 'family_space',
    ): array {
        $ids = [];
        foreach (range(1, $count) as $number) {
            $uploadId = (string) Str::ulid();
            $photoId = (string) Str::ulid();
            $ids[] = $photoId;
            $createdAt = now()->subSeconds($number);
            $this->admin->table('media_uploads')->insert([
                'id' => $uploadId, 'family_space_id' => $familyId, 'user_id' => $creatorId,
                'state' => 'ready', 'staging_object_key' => "staging/{$uploadId}",
                'client_filename' => "archive-{$number}.jpg", 'idempotency_key' => $uploadId,
                'request_fingerprint' => hash('sha256', $uploadId),
                'correlation_id' => (string) Str::uuid(),
                'traceparent' => '00-'.bin2hex(random_bytes(16)).'-'.bin2hex(random_bytes(8)).'-01',
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
            $this->admin->table('photos')->insert([
                'id' => $photoId, 'family_space_id' => $familyId, 'media_upload_id' => $uploadId,
                'created_by' => $creatorId, 'visibility' => $visibility, 'caption' => $caption,
                'historical_date' => now()->subDays($number)->toDateString(),
                'historical_date_precision' => 'exact',
                'created_at' => $createdAt, 'updated_at' => $createdAt,
            ]);
        }

        return $ids;
    }

    private function album(string $familyId, int $creatorId, string $name, string $visibility = 'family_space'): string
    {
        $id = (string) Str::ulid();
        $this->admin->table('albums')->insert([
            'id' => $id, 'family_space_id' => $familyId, 'created_by' => $creatorId,
            'name' => $name, 'visibility' => $visibility,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /** @return list<string> */
    private function collectPages(User $viewer, string $path): array
    {
        $ids = [];
        $cursor = null;
        do {
            $response = $this->actingAs($viewer)->getJson(
                $path.($cursor === null ? '' : '&cursor='.urlencode($cursor)),
            )->assertOk();
            $ids = [...$ids, ...$response->collect('data.items')->pluck('id')->all()];
            $cursor = $response->json('data.next_cursor');
        } while (is_string($cursor));

        return $ids;
    }

    /** @return list<string> */
    private function expectedOrder(string $familyId, string $sort): array
    {
        $query = $this->admin->table('photos')->where('family_space_id', $familyId)
            ->where('visibility', 'family_space');
        if ($sort === 'recently_added') {
            return $query->orderByDesc('created_at')->orderByDesc('id')->pluck('id')->all();
        }
        $direction = $sort === 'oldest' ? 'asc' : 'desc';

        return $query->orderByRaw('CASE WHEN historical_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('historical_date', $direction)->orderBy('id', $direction)->pluck('id')->all();
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function plan(string $sql, array $bindings): array
    {
        $row = $this->admin->selectOne("EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) {$sql}", $bindings);
        $this->assertNotNull($row);

        return json_decode($row->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
    }
}
