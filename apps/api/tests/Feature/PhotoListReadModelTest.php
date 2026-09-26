<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoListReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_counts_only_readable_conversation_contexts_and_reports_actual_memberships(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-list-counts']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $zero = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $readable = $this->albumWithPhoto($family, $owner, $photo, AlbumVisibility::FamilySpace);
        $hidden = $this->albumWithPhoto($family, $owner, $photo, AlbumVisibility::Private);

        foreach ([null, $readable->id, $hidden->id] as $albumId) {
            PhotoComment::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'album_id' => $albumId,
                'author_id' => $owner->id,
                'body' => 'Counted only when its context is readable.',
            ]);
            PhotoReaction::query()->create([
                'family_space_id' => $family->id,
                'photo_id' => $photo->id,
                'album_id' => $albumId,
                'user_id' => $owner->id,
                'reaction' => 'love',
            ]);
        }
        PhotoReaction::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'album_id' => $readable->id,
            'user_id' => $viewer->id,
            'reaction' => 'remember',
        ]);

        /** @var array<int, array<string, mixed>> $memberPhotoData */
        $memberPhotoData = $this->actingAs($viewer)
            ->getJson('/api/families/photo-list-counts/photos')
            ->assertOk()->json('data');
        $memberPhotos = collect($memberPhotoData)->keyBy('id');

        $this->assertSame(2, $memberPhotos[$photo->id]['love_count']);
        $this->assertSame(2, $memberPhotos[$photo->id]['comment_count']);
        $this->assertSame(2, $memberPhotos[$photo->id]['album_count']);
        $this->assertSame(0, $memberPhotos[$zero->id]['love_count']);
        $this->assertSame(0, $memberPhotos[$zero->id]['comment_count']);
        $this->assertSame(0, $memberPhotos[$zero->id]['album_count']);

        /** @var array<int, array<string, mixed>> $ownerPhotoData */
        $ownerPhotoData = $this->actingAs($owner)
            ->getJson('/api/families/photo-list-counts/photos')
            ->assertOk()->json('data');
        $ownerPhoto = collect($ownerPhotoData)->firstWhere('id', $photo->id);

        $this->assertSame(3, $ownerPhoto['love_count']);
        $this->assertSame(3, $ownerPhoto['comment_count']);
        $this->assertSame(2, $ownerPhoto['album_count']);
    }

    public function test_without_album_uses_same_family_membership_truth_and_preserves_photo_visibility(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photos-without-album']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $orphan = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $oneMembership = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $multipleMemberships = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $privateOrphan = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => 'private',
        ]);
        $hiddenAlbum = $this->albumWithPhoto(
            $family,
            $owner,
            $oneMembership,
            AlbumVisibility::Private,
        );
        $this->albumWithPhoto($family, $owner, $multipleMemberships, AlbumVisibility::FamilySpace);
        $this->albumWithPhoto($family, $owner, $multipleMemberships, AlbumVisibility::Private);

        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-photo-family']);
        $otherOwner = $this->member($otherFamily, FamilySpaceRole::Owner);
        $foreignOrphan = Photo::factory()->create([
            'family_space_id' => $otherFamily->id,
            'created_by' => $otherOwner->id,
        ]);

        /** @var array<int, array<string, mixed>> $allPhotoData */
        $allPhotoData = $this->actingAs($viewer)
            ->getJson('/api/families/photos-without-album/photos')
            ->assertOk()->json('data');
        $all = collect($allPhotoData)->keyBy('id');
        $this->assertSame(0, $all[$orphan->id]['album_count']);
        $this->assertSame(1, $all[$oneMembership->id]['album_count']);
        $this->assertSame(2, $all[$multipleMemberships->id]['album_count']);
        $this->assertArrayNotHasKey($privateOrphan->id, $all->all());
        $this->assertArrayNotHasKey($foreignOrphan->id, $all->all());

        /** @var array<int, array<string, mixed>> $filteredPhotoData */
        $filteredPhotoData = $this->actingAs($viewer)
            ->getJson('/api/families/photos-without-album/photos?without_album=1')
            ->assertOk()->json('data');
        $filtered = collect($filteredPhotoData);

        $this->assertSame([$orphan->id], $filtered->pluck('id')->all());
        $this->assertFalse($filtered->contains('id', $oneMembership->id));
        $this->assertFalse($filtered->contains('id', $multipleMemberships->id));
        $this->assertFalse($filtered->contains('id', $privateOrphan->id));
        $this->assertFalse($filtered->contains('id', $foreignOrphan->id));
        $this->assertSame(AlbumVisibility::Private, $hiddenAlbum->visibility);
    }

    public function test_list_query_count_is_bounded_as_photo_count_grows(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-list-query-count']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson('/api/families/photo-list-query-count/photos')->assertOk();
        $onePhotoQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        Photo::factory()->count(20)->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)->getJson('/api/families/photo-list-query-count/photos')
            ->assertOk()->assertJsonCount(21, 'data');
        $manyPhotoQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($onePhotoQueries, $manyPhotoQueries);
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }

    private function albumWithPhoto(
        FamilySpace $family,
        User $creator,
        Photo $photo,
        AlbumVisibility $visibility,
    ): Album {
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => 'Family memories',
            'visibility' => $visibility,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $creator->id,
        ]);

        return $album;
    }
}
