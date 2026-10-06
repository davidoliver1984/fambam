<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\PersonProposalStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\Tag;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PhotoIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_index_uses_complete_bounded_cursor_pages_for_every_frozen_sort(): void
    {
        CarbonImmutable::setTestNow('2026-10-05T12:00:00Z');
        $family = FamilySpace::factory()->create(['slug' => 'paged-photos']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $older = $this->photo($family, $owner, 'Older', '1980-01-01', '2026-09-04 10:00:00');
        $ties = [
            $this->photo($family, $owner, 'Tie A', '1990-05-01', '2026-09-02 10:00:00'),
            $this->photo($family, $owner, 'Tie B', '1990-05-01', '2026-09-03 10:00:00'),
        ];
        $newer = $this->photo($family, $owner, 'Newer', '2000-01-01', '2026-09-01 10:00:00');
        $undated = [
            $this->photo($family, $owner, 'Undated A', null, '2026-09-06 10:00:00'),
            $this->photo($family, $owner, 'Undated B', null, '2026-09-05 10:00:00'),
        ];

        $newest = $this->collectPages($owner, '/api/families/paged-photos/photos?limit=2&sort=newest');
        $this->assertSame([
            $newer->id,
            ...collect($ties)->sortByDesc('id')->pluck('id')->all(),
            $older->id,
            ...collect($undated)->sortByDesc('id')->pluck('id')->all(),
        ], $newest);

        $oldest = $this->collectPages($owner, '/api/families/paged-photos/photos?limit=2&sort=oldest');
        $this->assertSame([
            $older->id,
            ...collect($ties)->sortBy('id')->pluck('id')->all(),
            $newer->id,
            ...collect($undated)->sortBy('id')->pluck('id')->all(),
        ], $oldest);

        $recent = $this->collectPages($owner, '/api/families/paged-photos/photos?limit=2&sort=recently_added');
        $this->assertSame(
            collect([$older, ...$ties, $newer, ...$undated])
                ->sortByDesc(fn (Photo $photo): string => $photo->created_at->format('Y-m-d H:i:s').$photo->id)
                ->pluck('id')->all(),
            $recent,
        );
        $this->assertCount(count(array_unique($newest)), $newest);
        CarbonImmutable::setTestNow();
    }

    public function test_photo_cursors_are_signed_and_bound_to_family_search_filters_and_sort(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-cursor']);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-photo-cursor']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $this->member($otherFamily, FamilySpaceRole::Owner, $owner);
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        foreach (range(1, 3) as $number) {
            $photo = $this->photo($family, $owner, "Needle {$number}", "200{$number}-01-01");
            PhotoPerson::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'person_id' => $person->id,
                'status' => PersonProposalStatus::Approved,
                'proposed_by' => $owner->id,
            ]);
        }
        $this->photo($otherFamily, $owner, 'Needle foreign', '2004-01-01');

        $first = $this->actingAs($owner)->getJson(
            "/api/families/photo-cursor/photos?limit=1&q=Needle&person_id={$person->id}&sort=newest",
        )->assertOk();
        $cursor = urlencode((string) $first->json('data.next_cursor'));
        $this->actingAs($owner)->getJson(
            "/api/families/photo-cursor/photos?limit=1&q=Different&person_id={$person->id}&sort=newest&cursor={$cursor}",
        )->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson(
            "/api/families/photo-cursor/photos?limit=1&q=Needle&sort=newest&cursor={$cursor}",
        )->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson(
            "/api/families/photo-cursor/photos?limit=1&q=Needle&person_id={$person->id}&sort=oldest&cursor={$cursor}",
        )->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson(
            "/api/families/other-photo-cursor/photos?limit=1&q=Needle&person_id={$person->id}&sort=newest&cursor={$cursor}",
        )->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson('/api/families/photo-cursor/photos?cursor=not-a-cursor')
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($owner)->getJson('/api/families/photo-cursor/photos?limit=51')
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_general_search_covers_only_the_frozen_photo_vocabulary_and_preserves_visibility(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-search']);
        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-photo-search']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $otherOwner = $this->member($otherFamily, FamilySpaceRole::Owner);
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Margaret Shaw',
        ]);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id,
            'label' => 'Family holiday',
            'normalized_label' => 'family holiday',
            'created_by' => $owner->id,
        ]);
        $match = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'caption' => 'At the pier',
            'description' => 'A seaside promenade memory',
            'archive_source_description' => 'Blue shoebox',
            'location_description' => 'Blackpool',
            'historical_date_precision' => 'exact',
            'historical_date' => '1986-08-14',
        ]);
        $match->mediaUpload()->update(['client_filename' => 'mercer-scan-0042.jpg']);
        $match->tags()->attach($tag->id, [
            'family_space_id' => $family->id,
            'added_by' => $owner->id,
            'created_at' => now(),
        ]);
        PhotoPerson::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $match->id,
            'person_id' => $person->id,
            'status' => PersonProposalStatus::Approved,
            'proposed_by' => $owner->id,
        ]);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => 'private',
            'caption' => 'At the pier private',
        ]);
        Photo::factory()->create([
            'family_space_id' => $otherFamily->id,
            'created_by' => $otherOwner->id,
            'caption' => 'At the pier foreign',
        ]);

        foreach (['pier', 'promenade', 'shoebox', 'blackpool', 'mercer-scan', 'Margaret', 'family holiday', 'August 1986'] as $term) {
            $response = $this->actingAs($viewer)->getJson(
                '/api/families/photo-search/photos?q='.urlencode($term),
            )->assertOk();
            $this->assertSame([$match->id], $response->collect('data.items')->pluck('id')->all(), $term);
        }
        $this->actingAs($viewer)->getJson('/api/families/photo-search/photos?q=absent')
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_search_filters_without_album_and_pagination_compose_before_page_slicing(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-composition']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Jane Mercer']);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id,
            'label' => 'Seaside',
            'normalized_label' => 'seaside',
            'created_by' => $owner->id,
        ]);
        $matches = collect(range(1, 3))->map(function (int $number) use ($family, $owner, $person, $tag): Photo {
            $photo = Photo::factory()->create([
                'family_space_id' => $family->id,
                'created_by' => $owner->id,
                'caption' => "Holiday needle {$number}",
                'location_description' => 'Blackpool promenade',
                'historical_date_precision' => 'exact',
                'historical_date' => "1986-08-1{$number}",
            ]);
            $photo->tags()->attach($tag->id, [
                'family_space_id' => $family->id,
                'added_by' => $owner->id,
                'created_at' => now(),
            ]);
            PhotoPerson::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'person_id' => $person->id,
                'status' => PersonProposalStatus::Approved,
                'proposed_by' => $owner->id,
            ]);

            return $photo;
        });
        $this->photo($family, $owner, 'Unrelated', '1986-08-20');
        $query = http_build_query([
            'q' => 'needle',
            'person_id' => $person->id,
            'tag' => 'Seaside',
            'location' => 'blackpool',
            'historical_year' => 1986,
            'without_album' => 1,
            'limit' => 1,
            'sort' => 'oldest',
        ]);

        $this->assertSame(
            $matches->sortBy(fn (Photo $photo): string => $photo->historical_date->format('Y-m-d').$photo->id)
                ->pluck('id')->all(),
            $this->collectPages($owner, "/api/families/photo-composition/photos?{$query}"),
        );
    }

    /** @return list<string> */
    private function collectPages(User $actor, string $path): array
    {
        $ids = [];
        $cursor = null;
        $pageCount = 0;
        do {
            $pageCount++;
            $this->assertLessThanOrEqual(20, $pageCount);
            $separator = str_contains($path, '?') ? '&' : '?';
            $response = $this->actingAs($actor)->getJson(
                $path.($cursor === null ? '' : $separator.'cursor='.urlencode($cursor)),
            )->assertOk();
            $pageIds = $response->collect('data.items')->pluck('id')->all();
            $this->assertNotEmpty($pageIds);
            $ids = [...$ids, ...$pageIds];
            $cursor = $response->json('data.next_cursor');
        } while (is_string($cursor));

        return $ids;
    }

    private function photo(
        FamilySpace $family,
        User $creator,
        string $caption,
        ?string $historicalDate,
        ?string $createdAt = null,
    ): Photo {
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'caption' => $caption,
            'historical_date_precision' => $historicalDate === null ? null : 'exact',
            'historical_date' => $historicalDate,
        ]);
        if ($createdAt !== null) {
            DB::table('photos')->where('id', $photo->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        return $photo->refresh();
    }

    private function member(
        FamilySpace $family,
        FamilySpaceRole $role,
        ?User $user = null,
    ): User {
        $user ??= User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}
