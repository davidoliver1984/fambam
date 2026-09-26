<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\DatePrecision;
use App\Enums\FamilyActivityType;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Media\MediaSigningAudience;
use App\Models\Album;
use App\Models\FamilyActivity;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoVersion;
use App\Models\Reaction;
use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class HomeReadModelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaDeliveryUrlSigner::class, new HomeReadModelUrlSigner);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_home_shapes_only_supported_authorized_activity_with_batched_engagement_and_feature_media(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'home-feed']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Blackpool holiday',
            'description' => $this->document('Grandma maintained the chips were too salty.'),
            'visibility' => AlbumVisibility::FamilySpace,
            'starts_on' => '1986-07-01',
            'ends_on' => '1986-07-07',
            'location' => 'Blackpool',
        ]);
        $first = $this->photo($family, $owner, '01M00000000000000000000001', PhotoVisibility::FamilySpace, 'At the pier');
        $second = $this->photo($family, $owner, '01M00000000000000000000002', PhotoVisibility::FamilySpace, 'On the beach');
        $private = $this->photo($family, $owner, '01M00000000000000000000003', PhotoVisibility::Private, 'Private moment');
        foreach ([$first, $second] as $position => $photo) {
            $album->photos()->attach($photo->id, [
                'id' => (string) Str::ulid(),
                'family_space_id' => $family->id,
                'position' => $position + 1,
                'added_by' => $owner->id,
            ]);
        }
        FamilyActivity::query()->create([
            'family_space_id' => $family->id,
            'actor_user_id' => $owner->id,
            'action_type' => FamilyActivityType::PhotosAddedToAlbum,
            'subject_album_id' => $album->id,
            'contribution_batch_id' => '01M10000000000000000000000',
            'photo_ids' => [$second->id, $private->id, $first->id],
            'created_at' => now()->subMinute(),
        ]);
        FamilyActivity::query()->create([
            'family_space_id' => $family->id,
            'actor_user_id' => $owner->id,
            'action_type' => FamilyActivityType::AlbumCreated,
            'subject_album_id' => $album->id,
            'photo_ids' => [],
            'created_at' => now()->subMinutes(2),
        ]);
        Reaction::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'user_id' => $owner->id,
            'reaction' => 'love',
        ]);
        foreach ([$first, $private] as $photo) {
            PhotoComment::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'album_id' => $album->id,
                'author_id' => $owner->id,
                'body' => $this->document('A comment'),
            ]);
        }

        $story = Story::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $first->id,
            'author_id' => $owner->id,
            'body' => $this->document('The camera nearly stayed on the pier.'),
            'body_plain_text' => 'The camera nearly stayed on the pier.',
        ]);
        FamilyActivity::query()->create([
            'family_space_id' => $family->id,
            'actor_user_id' => $owner->id,
            'action_type' => FamilyActivityType::StoryAdded,
            'subject_story_id' => $story->id,
            'photo_ids' => [$first->id],
            'created_at' => now(),
        ]);
        Reaction::query()->create([
            'family_space_id' => $family->id,
            'story_id' => $story->id,
            'user_id' => $viewer->id,
            'reaction' => 'love',
        ]);
        StoryComment::query()->create([
            'family_space_id' => $family->id,
            'story_id' => $story->id,
            'author_id' => $viewer->id,
            'body' => $this->document('I remember that.'),
        ]);
        foreach (range(1, 25) as $offset) {
            FamilyActivity::query()->create([
                'family_space_id' => $family->id,
                'actor_user_id' => $owner->id,
                'action_type' => FamilyActivityType::AlbumCreated,
                'subject_album_id' => $album->id,
                'photo_ids' => [],
                'created_at' => now()->addMinutes($offset),
            ]);
        }

        $response = $this->actingAs($viewer)->getJson('/api/families/home-feed/home')->assertOk();
        $items = collect($this->jsonItems($response, 'data.activity'));

        $this->assertCount(2, $items);
        $this->assertSame([
            FamilyActivityType::PhotosAddedToAlbum->value,
            FamilyActivityType::StoryAdded->value,
        ], $items->pluck('action_type')->sort()->values()->all());
        $contribution = $items->firstWhere('action_type', FamilyActivityType::PhotosAddedToAlbum->value);
        $this->assertSame(2, $contribution['photo_count']);
        $this->assertSame($first->id, $contribution['feature_photo']['id']);
        $this->assertSame('At the pier', $contribution['feature_photo']['alt']);
        $this->assertSame('Blackpool holiday', $contribution['album']['name']);
        $this->assertSame('Grandma maintained the chips were too salty.', $contribution['album']['description']);
        $this->assertSame(['love_count' => 1, 'comment_count' => 1], $contribution['engagement']);
        $this->assertStringNotContainsString('original', $contribution['feature_photo']['presentation']['url']);
        $this->assertNotContains($private->id, $contribution['photo_ids']);

        $storyItem = $items->firstWhere('action_type', FamilyActivityType::StoryAdded->value);
        $this->assertSame($story->id, $storyItem['story']['id']);
        $this->assertSame(['love_count' => 1, 'comment_count' => 1], $storyItem['engagement']);
    }

    public function test_home_returns_fifteen_authorized_latest_presentations_and_honors_active_version(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'latest-home']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $photos = collect();
        foreach (range(1, 17) as $index) {
            $photo = Photo::factory()->create([
                'family_space_id' => $family->id,
                'created_by' => $owner->id,
                'caption' => "Visible {$index}",
                'created_at' => now()->subMinutes(17 - $index),
            ]);
            $photos->push($photo);
        }
        $edited = $photos->last();
        $version = PhotoVersion::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $edited->id,
            'edit_recipe' => ['schema_version' => 1, 'operations' => []],
            'derived_object_key' => "families/{$family->id}/photos/{$edited->id}/edited.webp",
            'created_by' => $owner->id,
        ]);
        $edited->forceFill(['active_photo_version_id' => $version->id])->save();
        $private = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
            'created_at' => now()->addMinute(),
        ]);
        $suppressed = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'do_not_resurface' => true,
            'created_at' => now()->addMinutes(2),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->actingAs($viewer)->getJson('/api/families/latest-home/home')->assertOk();
        $latest = collect($this->jsonItems($response, 'data.latest_photos'));
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(15, $latest);
        $this->assertSame($edited->id, $latest->first()['id']);
        $this->assertSame($version->id, $latest->first()['active_photo_version_id']);
        $this->assertStringContainsString('edited.webp', urldecode($latest->first()['presentation']['url']));
        $this->assertNotContains($private->id, $latest->pluck('id'));
        $this->assertNotContains($suppressed->id, $latest->pluck('id'));
        $this->assertFalse($latest->contains(fn (array $item): bool => str_contains($item['presentation']['url'], 'original')));
        $this->assertLessThanOrEqual(30, $queryCount, 'Home presentation query count must remain bounded.');
    }

    public function test_on_this_day_exposes_only_direct_photo_location_and_authorized_presentation(): void
    {
        CarbonImmutable::setTestNow('2026-09-26 12:00:00');
        $family = FamilySpace::factory()->create(['slug' => 'dated-home']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $memory = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'caption' => 'Christmas at Nan’s',
            'historical_date_precision' => DatePrecision::Exact,
            'historical_date' => '1994-09-26',
            'location_description' => 'Ashton-under-Lyne',
        ]);
        Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
            'historical_date_precision' => DatePrecision::Exact,
            'historical_date' => '2000-09-26',
            'location_description' => 'Private place',
        ]);

        $response = $this->actingAs($viewer)->getJson('/api/families/dated-home/home')
            ->assertOk()
            ->assertJsonPath('data.on_this_day.photo_id', $memory->id)
            ->assertJsonPath('data.on_this_day.location', 'Ashton-under-Lyne')
            ->assertJsonPath('data.on_this_day.photo.id', $memory->id);
        $response->assertJsonMissing(['location' => 'Private place']);

        $memory->update(['location_description' => null]);
        $this->actingAs($viewer)->getJson('/api/families/dated-home/home')
            ->assertOk()->assertJsonPath('data.on_this_day.location', null);
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'state' => MembershipState::Active,
        ]);

        return $user;
    }

    private function photo(FamilySpace $family, User $owner, string $id, PhotoVisibility $visibility, string $caption): Photo
    {
        return Photo::factory()->create([
            'id' => $id,
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => $visibility,
            'caption' => $caption,
        ]);
    }

    /**
     * @param  TestResponse<Response>  $response
     * @return list<array<string, mixed>>
     */
    private function jsonItems(TestResponse $response, string $path): array
    {
        $value = $response->json($path);
        if (! is_array($value)) {
            throw new \UnexpectedValueException("{$path} is not an array.");
        }
        $items = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                throw new \UnexpectedValueException("{$path} contains a non-array item.");
            }
            $normalized = [];
            foreach ($item as $key => $field) {
                if (is_string($key)) {
                    $normalized[$key] = $field;
                }
            }
            $items[] = $normalized;
        }

        return $items;
    }

    /** @return array{schema_version: int, blocks: list<array<string, mixed>>} */
    private function document(string $text): array
    {
        return ['schema_version' => 1, 'blocks' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => $text]],
        ]]];
    }
}

final class HomeReadModelUrlSigner implements MediaDeliveryUrlSigner
{
    public function authorizeRead(
        string $key,
        string $responseContentType,
        DateTimeInterface $expiresAt,
        MediaSigningAudience $audience,
    ): MediaDeliveryAuthorization {
        return new MediaDeliveryAuthorization(
            'https://storage.test/'.rawurlencode($key),
            CarbonImmutable::instance($expiresAt),
        );
    }
}
