<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoStory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_metadata_search_starts_from_visible_photo_album_and_story_queries(): void
    {
        [$family, $owner, $member] = $this->family();
        $visible = $this->photo($family, $owner, 'Summer beach', PhotoVisibility::FamilySpace);
        $hidden = $this->photo($family, $owner, 'Private beach', PhotoVisibility::Private);
        PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $visible->id,
            'body' => 'A beach story the family remembers.',
            'author_id' => $owner->id,
        ]);
        PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $hidden->id,
            'body' => 'A private beach story.',
            'author_id' => $owner->id,
        ]);
        $visibleAlbum = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Beach holiday',
            'visibility' => AlbumVisibility::FamilySpace,
        ]);
        Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Private beach plans',
            'visibility' => AlbumVisibility::Selected,
        ]);

        $response = $this->actingAs($member)->getJson("/api/families/{$family->slug}/search?q=beach");

        $response->assertOk()
            ->assertJsonCount(1, 'data.photos.items')
            ->assertJsonPath('data.photos.items.0.id', $visible->id)
            ->assertJsonCount(1, 'data.albums.items')
            ->assertJsonPath('data.albums.items.0.id', $visibleAlbum->id)
            ->assertJsonCount(1, 'data.stories.items')
            ->assertJsonPath('data.stories.items.0.photo_id', $visible->id);
        $this->assertStringNotContainsString($hidden->id, $response->getContent());
    }

    public function test_tags_are_relational_search_signals_and_approved_people_remain_on_visible_photo_summaries(): void
    {
        [$family, $owner, $member] = $this->family();
        $photo = $this->photo($family, $owner, null, PhotoVisibility::FamilySpace);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id,
            'label' => 'Seaside',
            'normalized_label' => 'seaside',
        ]);
        $photo->tags()->attach($tag->id, ['family_space_id' => $family->id, 'added_by' => $owner->id]);
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'David Archive',
        ]);
        $photo->photoPeople()->create([
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($member)->getJson("/api/families/{$family->slug}/search?q=seaside");

        $response->assertOk()
            ->assertJsonPath('data.photos.items.0.id', $photo->id)
            ->assertJsonPath('data.photos.items.0.people.0.id', $person->id)
            ->assertJsonPath('data.photos.items.0.people.0.preferred_name', 'David Archive');

        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search?tag_id={$tag->id}")
            ->assertOk()
            ->assertJsonPath('data.photos.items.0.id', $photo->id)
            ->assertJsonCount(0, 'data.albums.items')
            ->assertJsonCount(0, 'data.events.items');
    }

    public function test_historical_windows_overlap_and_unknown_dates_never_use_created_at(): void
    {
        [$family, $owner, $member] = $this->family();
        $decade = $this->photo($family, $owner, 'Decade photograph', PhotoVisibility::FamilySpace, '1980-01-01', 'decade');
        $this->photo($family, $owner, 'Unknown photograph', PhotoVisibility::FamilySpace, null, 'unknown');

        $response = $this->actingAs($member)->getJson(
            "/api/families/{$family->slug}/search?date_from=1985-01-01&date_to=1985-12-31",
        );

        $response->assertOk()->assertJsonCount(1, 'data.photos.items')
            ->assertJsonPath('data.photos.items.0.id', $decade->id)
            ->assertJsonPath('data.photos.items.0.historical_date.precision', 'decade')
            ->assertJsonPath('data.photos.items.0.historical_date.value', '1980s');
    }

    public function test_each_group_has_stable_opaque_cursor_pagination_and_rejects_invalid_tokens(): void
    {
        [$family, $owner, $member] = $this->family();
        $this->photo($family, $owner, 'Archive match', PhotoVisibility::FamilySpace);
        $this->photo($family, $owner, 'Archive match', PhotoVisibility::FamilySpace);
        $this->photo($family, $owner, 'Archive match', PhotoVisibility::FamilySpace);
        $base = "/api/families/{$family->slug}/search?q=archive&group=photos&limit=1";

        $first = $this->actingAs($member)->getJson($base)->assertOk();
        $cursor = $first->json('data.photos.next_cursor');
        $this->assertIsString($cursor);
        $second = $this->actingAs($member)->getJson($base.'&cursor='.urlencode($cursor))->assertOk();
        $this->assertNotSame(
            $first->json('data.photos.items.0.id'),
            $second->json('data.photos.items.0.id'),
        );
        $this->assertNull($second->json('data.albums'));

        $this->actingAs($member)->getJson($base.'&cursor=not-a-valid-cursor')
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
    }

    /** @return array{FamilySpace, User, User} */
    private function family(): array
    {
        $family = FamilySpace::factory()->create(['slug' => 'search-family']);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        foreach ([[$owner, FamilySpaceRole::Owner], [$member, FamilySpaceRole::Member]] as [$user, $role]) {
            FamilySpaceMembership::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $user->id,
                'role' => $role,
                'state' => MembershipState::Active,
            ]);
        }

        return [$family, $owner, $member];
    }

    private function photo(
        FamilySpace $family,
        User $creator,
        ?string $caption,
        PhotoVisibility $visibility,
        ?string $historicalDate = null,
        ?string $precision = null,
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'caption' => $caption,
            'visibility' => $visibility,
            'historical_date' => $historicalDate,
            'historical_date_precision' => $precision,
        ]);
    }
}
