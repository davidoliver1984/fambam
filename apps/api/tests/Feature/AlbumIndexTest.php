<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaObjectStorage;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlbumIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaObjectStorage::class, $this->createStub(MediaObjectStorage::class));
        $this->app->instance(MediaDeliveryUrlSigner::class, $this->createStub(MediaDeliveryUrlSigner::class));
    }

    public function test_album_index_uses_bounded_cursor_pages_without_duplicates_or_missing_null_dates(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'paged-albums']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $dated = [
            $this->album($family, $owner, 'First', '2024-01-01'),
            $this->album($family, $owner, 'Second', '2024-01-02'),
            $this->album($family, $owner, 'Third', '2024-01-03'),
            $this->album($family, $owner, 'Fourth', '2024-01-04'),
        ];
        $undated = [
            $this->album($family, $owner, 'Undated A'),
            $this->album($family, $owner, 'Undated B'),
        ];

        $ids = $this->collectPages($owner, '/api/families/paged-albums/albums?limit=2&sort=newest');
        $expectedDated = collect($dated)->sort(function (Album $left, Album $right): int {
            return [$right->starts_on?->format('Y-m-d'), $right->id]
                <=> [$left->starts_on?->format('Y-m-d'), $left->id];
        })->pluck('id')->all();
        $expectedUndated = collect($undated)->sortByDesc('id')->pluck('id')->all();

        $this->assertSame([...$expectedDated, ...$expectedUndated], $ids);
        $this->assertCount(count(array_unique($ids)), $ids);
    }

    public function test_all_album_sorts_are_deterministic_and_cursors_are_query_bound(): void
    {
        CarbonImmutable::setTestNow('2026-10-04T12:00:00Z');
        $family = FamilySpace::factory()->create(['slug' => 'album-sorts']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $sameDate = [
            $this->album($family, $owner, 'Tie A', '2020-05-01', '2026-08-01 10:00:00'),
            $this->album($family, $owner, 'Tie B', '2020-05-01', '2026-09-01 10:00:00'),
        ];
        $older = $this->album($family, $owner, 'Older', '2019-05-01', '2026-10-01 10:00:00');
        $undated = $this->album($family, $owner, 'Undated', null, '2026-07-01 10:00:00');

        $oldest = $this->collectPages($owner, '/api/families/album-sorts/albums?limit=1&sort=oldest');
        $this->assertSame([
            $older->id,
            ...collect($sameDate)->sortBy('id')->pluck('id')->all(),
            $undated->id,
        ], $oldest);

        $updated = $this->collectPages($owner, '/api/families/album-sorts/albums?limit=2&sort=updated');
        $this->assertSame([$older->id, $sameDate[1]->id, $sameDate[0]->id, $undated->id], $updated);

        $first = $this->actingAs($owner)->getJson('/api/families/album-sorts/albums?limit=1&sort=newest')
            ->assertOk();
        $cursor = urlencode((string) $first->json('data.next_cursor'));
        $this->actingAs($owner)->getJson("/api/families/album-sorts/albums?limit=1&sort=oldest&cursor={$cursor}")
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson('/api/families/album-sorts/albums?cursor=not-a-cursor')
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        CarbonImmutable::setTestNow();
    }

    public function test_album_search_and_filters_remain_server_side_across_pages(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'filtered-albums']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id, 'name' => 'Mercer reunion',
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Alice Mercer']);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id, 'label' => 'Seaside', 'normalized_label' => 'seaside',
            'created_by' => $owner->id,
        ]);
        $matches = collect(range(1, 3))->map(function (int $number) use ($family, $owner, $event, $person, $tag): Album {
            $album = $this->album($family, $owner, "Holiday {$number}", "2022-07-0{$number}");
            $album->update(['location' => 'Brighton', 'event_id' => $event->id]);
            $album->people()->attach($person->id, [
                'id' => (string) Str::ulid(), 'family_space_id' => $family->id,
                'added_by' => $owner->id, 'created_at' => now(),
            ]);
            $album->tags()->attach($tag->id, [
                'family_space_id' => $family->id, 'added_by' => $owner->id, 'created_at' => now(),
            ]);

            return $album;
        });
        $this->album($family, $owner, 'Unrelated archive', '1990-01-01');
        $query = http_build_query([
            'q' => 'Alice', 'location' => 'bright', 'date_from' => '2022-01-01', 'date_to' => '2022-12-31',
            'tag_id' => $tag->id, 'person_ids' => [$person->id], 'event_id' => $event->id,
            'limit' => 1, 'sort' => 'oldest',
        ]);

        $this->assertSame(
            $matches->sortBy(fn (Album $album): string => $album->starts_on?->format('Y-m-d') ?? '')->pluck('id')->all(),
            $this->collectPages($owner, "/api/families/filtered-albums/albums?{$query}"),
        );
    }

    public function test_album_pages_preserve_authorization_family_isolation_and_page_size_bound(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-authority']);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-album-authority']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        [$otherOwner] = $this->member($otherFamily, FamilySpaceRole::Owner);
        $visible = $this->album($family, $owner, 'Visible');
        $this->album($family, $owner, 'Also visible');
        $private = $this->album($family, $owner, 'Private', visibility: AlbumVisibility::Private);
        $foreign = $this->album($otherFamily, $otherOwner, 'Foreign');

        $response = $this->actingAs($member)->getJson('/api/families/album-authority/albums?limit=50')
            ->assertOk()->assertJsonPath('data.next_cursor', null);
        $ids = $response->collect('data.items')->pluck('id')->all();
        $this->assertContains($visible->id, $ids);
        $this->assertNotContains($private->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->actingAs($member)->getJson('/api/families/album-authority/albums?limit=51')
            ->assertUnprocessable()->assertJsonValidationErrors('limit');

        FamilySpaceMembership::factory()->create([
            'family_space_id' => $otherFamily->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $cursor = $this->actingAs($member)->getJson('/api/families/album-authority/albums?limit=1')
            ->assertOk()->json('data.next_cursor');
        $this->actingAs($member)->getJson('/api/families/other-album-authority/albums?limit=1&cursor='.urlencode((string) $cursor))
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    public function test_album_newness_uses_created_at_with_an_inclusive_frozen_boundary(): void
    {
        CarbonImmutable::setTestNow('2026-10-04T12:00:00Z');
        $family = FamilySpace::factory()->create(['slug' => 'album-freshness']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $inside = $this->album($family, $owner, 'Inside', createdAt: '2026-09-20 12:00:00');
        $outside = $this->album($family, $owner, 'Outside', createdAt: '2026-09-20 11:59:59');
        $future = $this->album($family, $owner, 'Clock skew', createdAt: '2026-10-04 12:00:01');
        DB::table('albums')->where('id', $outside->id)->update(['updated_at' => now()]);

        $items = $this->actingAs($owner)->getJson('/api/families/album-freshness/albums?limit=10')
            ->assertOk()->collect('data.items')->keyBy('id');
        $this->assertTrue($items[$inside->id]['is_new']);
        $this->assertFalse($items[$outside->id]['is_new']);
        $this->assertTrue($items[$future->id]['is_new']);
        CarbonImmutable::setTestNow();
    }

    public function test_album_page_query_count_is_constant_as_the_page_fills(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'album-query-count']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        foreach (range(1, 12) as $number) {
            $this->album($family, $owner, "Album {$number}", sprintf('2025-01-%02d', $number));
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $one = $this->actingAs($owner)->getJson('/api/families/album-query-count/albums?limit=1')->assertOk();
        $singleItemQueries = $queries;
        $full = $this->actingAs($owner)->getJson('/api/families/album-query-count/albums?limit=12')->assertOk();
        $fullPageQueries = $queries - $singleItemQueries;

        $this->assertSame($singleItemQueries, $fullPageQueries);
        $this->assertSame(8, $fullPageQueries);
        $this->assertGreaterThan(strlen($one->getContent()), strlen($full->getContent()));
    }

    /** @return list<string> */
    private function collectPages(User $actor, string $path): array
    {
        $ids = [];
        $cursor = null;
        $pageCount = 0;
        do {
            $pageCount++;
            $this->assertLessThanOrEqual(
                20,
                $pageCount,
                "Album pagination did not reach a final page for {$path}: ".implode(',', $ids),
            );
            $separator = str_contains($path, '?') ? '&' : '?';
            $response = $this->actingAs($actor)->getJson($path.($cursor === null ? '' : $separator.'cursor='.urlencode($cursor)))
                ->assertOk();
            $pageIds = $response->collect('data.items')->pluck('id')->all();
            $this->assertNotEmpty($pageIds);
            $ids = [...$ids, ...$pageIds];
            $cursor = $response->json('data.next_cursor');
        } while (is_string($cursor));

        return $ids;
    }

    private function album(
        FamilySpace $family,
        User $creator,
        string $name,
        ?string $startsOn = null,
        ?string $updatedAt = null,
        AlbumVisibility $visibility = AlbumVisibility::FamilySpace,
        ?string $createdAt = null,
    ): Album {
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => $name,
            'starts_on' => $startsOn,
            'visibility' => $visibility,
        ]);
        $timestamps = array_filter([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt ?? $createdAt,
        ], fn (?string $value): bool => $value !== null);
        if ($timestamps !== []) {
            DB::table('albums')->where('id', $album->id)->update($timestamps);
        }

        return $album->refresh();
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id, 'user_id' => $user->id, 'role' => $role,
        ]);

        return [$user, $membership];
    }
}
