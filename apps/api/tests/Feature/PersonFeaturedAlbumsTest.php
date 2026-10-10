<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Media\MediaDeliveryAuthorization;
use App\Media\MediaDeliveryUrlSigner;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PersonFeaturedAlbumsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $signer = $this->createMock(MediaDeliveryUrlSigner::class);
        $signer->method('authorizeRead')->willReturnCallback(
            fn (string $key, string $type, DateTimeInterface $expiresAt): MediaDeliveryAuthorization => new MediaDeliveryAuthorization(
                'https://storage.test/'.rawurlencode($key),
                CarbonImmutable::instance($expiresAt),
            ),
        );
        $this->app->instance(MediaDeliveryUrlSigner::class, $signer);
    }

    public function test_only_explicit_authorised_album_people_associations_are_returned(): void
    {
        [$family, $owner, $member] = $this->family('person-featured-albums');
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $taggedOnly = $this->album($family, $owner, 'Tagged only', '2026-01-01');
        $taggedPhoto = $this->coverPhoto($family, $owner);
        $taggedOnly->photos()->attach($taggedPhoto->id, $this->albumPhotoPivot($family, $owner));
        $taggedPhoto->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);

        $explicit = $this->album($family, $owner, 'Explicit association', '2025-05-17');
        $cover = $this->coverPhoto($family, $owner);
        $explicit->photos()->attach($cover->id, $this->albumPhotoPivot($family, $owner));
        $explicit->update([
            'cover_photo_id' => $cover->id,
            'cover_focal_x' => 0.25,
            'cover_focal_y' => 0.75,
            'location' => 'Northbridge',
        ]);
        $this->associate($explicit, $person, $family, $owner);

        $hidden = $this->album($family, $owner, 'Hidden association', '2027-01-01', AlbumVisibility::Selected);
        $this->associate($hidden, $person, $family, $owner);
        [$otherFamily, $otherOwner] = $this->family('other-person-featured-albums');
        $otherPerson = Person::factory()->create(['family_space_id' => $otherFamily->id]);
        $foreign = $this->album($otherFamily, $otherOwner, 'Foreign association', '2028-01-01');
        $this->associate($foreign, $otherPerson, $otherFamily, $otherOwner);

        $response = $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/people/{$person->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.featured_albums')
            ->assertJsonPath('data.featured_albums.0.id', $explicit->id)
            ->assertJsonPath('data.featured_albums.0.name', 'Explicit association')
            ->assertJsonPath('data.featured_albums.0.starts_on', '2025-05-17')
            ->assertJsonPath('data.featured_albums.0.location', 'Northbridge')
            ->assertJsonPath('data.featured_albums.0.cover_focal_x', 0.25)
            ->assertJsonPath('data.featured_albums.0.cover_focal_y', 0.75);

        $this->assertStringContainsString('thumbnail.v1.webp', (string) $response->json('data.featured_albums.0.cover_thumbnail_url'));
        $this->assertNotContains($taggedOnly->id, $response->collect('data.featured_albums')->pluck('id'));
        $this->assertNotContains($hidden->id, $response->collect('data.featured_albums')->pluck('id'));
        $this->assertNotContains($foreign->id, $response->collect('data.featured_albums')->pluck('id'));

        $this->associate($taggedOnly, $person, $family, $owner);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.featured_albums.0.id', $taggedOnly->id)
            ->assertJsonCount(2, 'data.featured_albums');
    }

    public function test_featured_albums_are_newest_first_bounded_and_empty_for_a_sparse_person(): void
    {
        [$family, $owner] = $this->family('bounded-person-albums');
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $sparse = Person::factory()->create(['family_space_id' => $family->id]);
        $albums = collect(range(1, 8))->map(function (int $day) use ($family, $owner, $person): Album {
            $album = $this->album($family, $owner, "Album {$day}", sprintf('2025-01-%02d', $day));
            $this->associate($album, $person, $family, $owner);

            return $album;
        });

        $response = $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/people/{$person->id}")
            ->assertOk()
            ->assertJsonCount(6, 'data.featured_albums');
        $this->assertSame(
            $albums->sortByDesc(fn (Album $album): string => $album->starts_on?->format('Y-m-d') ?? '')->take(6)->pluck('id')->all(),
            $response->collect('data.featured_albums')->pluck('id')->all(),
        );
        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/people/{$sparse->id}")
            ->assertOk()
            ->assertJsonPath('data.featured_albums', []);
    }

    public function test_person_discovery_uses_album_people_instead_of_tagged_photos(): void
    {
        [$family, $owner] = $this->family('explicit-person-discovery');
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $album = $this->album($family, $owner, 'Discovery Album', '2025-01-01');
        $photo = $this->coverPhoto($family, $owner);
        $album->photos()->attach($photo->id, $this->albumPhotoPivot($family, $owner));
        $photo->photoPeople()->create([
            'family_space_id' => $family->id, 'person_id' => $person->id,
            'proposal_source' => 'human', 'status' => 'approved',
            'proposed_by' => $owner->id, 'resolved_by' => $owner->id, 'resolved_at' => now(),
        ]);

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/discover/people/{$person->id}")
            ->assertOk()->assertJsonCount(0, 'data.related.albums');

        $this->associate($album, $person, $family, $owner);
        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/discover/people/{$person->id}")
            ->assertOk()->assertJsonPath('data.related.albums.0.id', $album->id);
    }

    public function test_featured_album_query_count_is_constant_as_the_bounded_section_fills(): void
    {
        [$family, $owner] = $this->family('person-album-query-count');
        $single = Person::factory()->create(['family_space_id' => $family->id]);
        $full = Person::factory()->create(['family_space_id' => $family->id]);
        foreach (range(1, 6) as $day) {
            $album = $this->album($family, $owner, "Album {$day}", sprintf('2025-01-%02d', $day));
            $cover = $this->coverPhoto($family, $owner);
            $album->photos()->attach($cover->id, $this->albumPhotoPivot($family, $owner));
            $album->update([
                'cover_photo_id' => $cover->id,
                'cover_focal_x' => 0.5,
                'cover_focal_y' => 0.5,
            ]);
            $this->associate($album, $full, $family, $owner);
            if ($day === 1) {
                $this->associate($album, $single, $family, $owner);
            }
        }
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/people/{$single->id}")
            ->assertOk()->assertJsonCount(1, 'data.featured_albums');
        $singleQueries = $queries;
        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/people/{$full->id}")
            ->assertOk()->assertJsonCount(6, 'data.featured_albums');

        $this->assertSame($singleQueries, $queries - $singleQueries);
    }

    /** @return array{FamilySpace, User, User} */
    private function family(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        foreach ([[$owner, FamilySpaceRole::Owner], [$member, FamilySpaceRole::Member]] as [$user, $role]) {
            FamilySpaceMembership::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
        }

        return [$family, $owner, $member];
    }

    private function album(
        FamilySpace $family,
        User $owner,
        string $name,
        string $startsOn,
        AlbumVisibility $visibility = AlbumVisibility::FamilySpace,
    ): Album {
        return Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => $name,
            'starts_on' => $startsOn,
            'visibility' => $visibility,
        ]);
    }

    private function coverPhoto(FamilySpace $family, User $owner): Photo
    {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/media/".(string) Str::ulid().'/canonical.jpg',
            'canonical_mime_type' => 'image/jpeg',
        ]);
        MediaVariant::query()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'transform_name' => MediaVariantTransform::Thumbnail,
            'processing_version' => (int) config('media.processing.variant_processing_version'),
            'object_key' => "families/{$family->id}/media/{$upload->id}/variants/thumbnail.v1.webp",
            'mime_type' => 'image/webp',
            'sha256' => hash('sha256', 'thumbnail'),
            'pixel_width' => 320,
            'pixel_height' => 320,
            'byte_size' => 100,
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
    }

    /** @return array<string, mixed> */
    private function albumPhotoPivot(FamilySpace $family, User $owner): array
    {
        return [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
        ];
    }

    private function associate(Album $album, Person $person, FamilySpace $family, User $owner): void
    {
        DB::table('album_people')->insertOrIgnore([
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'person_id' => $person->id,
            'added_by' => $owner->id,
            'created_at' => now(),
        ]);
    }
}
