<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MediaVariantTransform;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\MediaVariant;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonDetailProposal;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PeopleIndexReadModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_are_cursor_paginated_in_stable_normalized_name_order(): void
    {
        [$family, $viewer] = $this->family('people-pages');
        foreach (['beta', 'Alpha', 'alpha', 'Delta', 'charlie'] as $name) {
            Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => $name]);
        }

        $first = $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/people?limit=2")
            ->assertOk()->assertJsonCount(2, 'data.items');
        $this->assertSame(['Alpha', 'alpha'], $first->json('data.items.*.preferred_name'));
        $this->assertNotNull($first->json('data.next_cursor'));

        $second = $this->actingAs($viewer)->getJson('/api/families/'.$family->slug.'/people?limit=2&cursor='.
            urlencode($first->json('data.next_cursor')))->assertOk();
        $third = $this->actingAs($viewer)->getJson('/api/families/'.$family->slug.'/people?limit=2&cursor='.
            urlencode($second->json('data.next_cursor')))->assertOk();

        $this->assertSame(['beta', 'charlie'], $second->json('data.items.*.preferred_name'));
        $this->assertSame(['Delta'], $third->json('data.items.*.preferred_name'));
        $this->assertNull($third->json('data.next_cursor'));
        $ids = array_merge(
            $first->json('data.items.*.id'),
            $second->json('data.items.*.id'),
            $third->json('data.items.*.id'),
        );
        $this->assertCount(5, array_unique($ids));

        $descendingPages = [];
        $descendingCursor = null;
        do {
            $response = $this->actingAs($viewer)->getJson(
                "/api/families/{$family->slug}/people?limit=2&sort=za".
                ($descendingCursor === null ? '' : '&cursor='.urlencode($descendingCursor)),
            )->assertOk();
            $descendingPages[] = $response->json('data.items.*.preferred_name');
            $descendingCursor = $response->json('data.next_cursor');
        } while ($descendingCursor !== null);
        $this->assertSame(
            ['Delta', 'charlie', 'beta', 'alpha', 'Alpha'],
            array_merge(...$descendingPages),
        );
        $this->assertCount(3, $descendingPages);
    }

    public function test_search_and_authoritative_status_filters_apply_before_pagination(): void
    {
        [$family, $viewer] = $this->family('people-filters');
        Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Living Match',
            'alternate_names' => ['Needle'],
            'is_deceased' => true,
            'death_date' => null,
        ]);
        $remembered = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Remembered Match',
            'alternate_names' => ['Needle'],
            'death_date' => '2001-02-03',
            'death_date_precision' => 'exact',
        ]);
        $rememberedSecond = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Remembered Needle Two',
            'death_date' => '2002-03-04',
            'death_date_precision' => 'exact',
        ]);
        PersonDetailProposal::query()->create([
            'family_space_id' => $family->id,
            'person_id' => Person::factory()->create([
                'family_space_id' => $family->id,
                'preferred_name' => 'Proposed Needle',
            ])->id,
            'changes' => ['death_date' => ['precision' => 'exact', 'value' => '2000-01-01']],
            'status' => 'pending',
        ]);
        [$foreign] = $this->family('people-filters-foreign');
        Person::factory()->create([
            'family_space_id' => $foreign->id,
            'preferred_name' => 'Foreign Needle',
            'death_date' => '1999-01-01',
        ]);

        $living = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people?q=needle&status=living&limit=1")
            ->assertOk();
        $this->assertSame('Living Match', $living->json('data.items.0.preferred_name'));
        $this->assertNotNull($living->json('data.next_cursor'));
        $livingNext = $this->actingAs($viewer)->getJson('/api/families/'.$family->slug.
            '/people?q=needle&status=living&limit=1&cursor='.urlencode($living->json('data.next_cursor')))
            ->assertOk();
        $this->assertSame('Proposed Needle', $livingNext->json('data.items.0.preferred_name'));

        $rememberedFirst = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people?q=needle&status=remembered&sort=za&limit=1")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $rememberedSecond->id)
            ->assertJsonPath('data.items.0.status', 'remembered');
        $this->actingAs($viewer)->getJson('/api/families/'.$family->slug.
            '/people?q=needle&status=remembered&sort=za&limit=1&cursor='.
            urlencode($rememberedFirst->json('data.next_cursor')))
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $remembered->id)
            ->assertJsonPath('data.next_cursor', null);
        $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people?q=absent")
            ->assertOk()->assertJsonCount(0, 'data.items');
    }

    public function test_cursor_is_signed_and_bound_to_family_sort_search_and_status(): void
    {
        [$firstFamily, $viewer] = $this->family('people-cursor-one');
        [$secondFamily] = $this->family('people-cursor-two', $viewer);
        foreach (['Alpha', 'Beta'] as $name) {
            Person::factory()->create(['family_space_id' => $firstFamily->id, 'preferred_name' => $name]);
        }
        $cursor = $this->actingAs($viewer)
            ->getJson("/api/families/{$firstFamily->slug}/people?limit=1&q=a&status=living&sort=az")
            ->assertOk()->json('data.next_cursor');

        foreach ([
            "/api/families/{$firstFamily->slug}/people?limit=1&q=a&status=living&sort=za&cursor=",
            "/api/families/{$firstFamily->slug}/people?limit=1&q=b&status=living&sort=az&cursor=",
            "/api/families/{$firstFamily->slug}/people?limit=1&q=a&status=all&sort=az&cursor=",
            "/api/families/{$secondFamily->slug}/people?limit=1&q=a&status=living&sort=az&cursor=",
        ] as $url) {
            $this->actingAs($viewer)->getJson($url.urlencode($cursor))->assertUnprocessable()
                ->assertJsonValidationErrors('cursor');
        }
        $this->actingAs($viewer)
            ->getJson("/api/families/{$firstFamily->slug}/people?cursor=not-a-cursor")
            ->assertUnprocessable()->assertJsonValidationErrors('cursor');
        $this->actingAs($viewer)
            ->getJson("/api/families/{$firstFamily->slug}/people?limit=51")
            ->assertUnprocessable()->assertJsonValidationErrors('limit');
    }

    public function test_default_page_size_is_bounded_to_twenty_four(): void
    {
        [$family, $viewer] = $this->family('people-default-limit');
        Person::factory()->count(30)->create(['family_space_id' => $family->id]);

        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/people")
            ->assertOk()
            ->assertJsonCount(24, 'data.items')
            ->assertJsonPath('data.next_cursor', fn (?string $cursor): bool => $cursor !== null);
    }

    public function test_summary_uses_authorized_portrait_and_direct_confirmed_viewer_relationship_only(): void
    {
        config([
            'filesystems.disks.s3.region' => 'eu-west-2',
            'filesystems.disks.s3.key' => 'test-key',
            'filesystems.disks.s3.secret' => 'test-secret',
            'filesystems.disks.s3.bucket' => 'test-bucket',
        ]);
        [$family, $owner, $viewer] = $this->familyWithOwnerAndMember('people-presentation');
        $viewerPerson = Person::factory()->create(['family_space_id' => $family->id]);
        $child = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Child']);
        $indirect = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Indirect']);
        $disputed = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Disputed']);
        $avatarOnly = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Avatar only']);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $viewerPerson->id,
            'user_id' => $viewer->id,
            'created_by' => $owner->id,
        ]);
        PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $viewerPerson->id,
            'related_person_id' => $child->id,
            'type' => 'parent_of',
            'status' => 'confirmed',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $child->id,
            'related_person_id' => $indirect->id,
            'type' => 'parent_of',
            'status' => 'confirmed',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $viewerPerson->id,
            'related_person_id' => $disputed->id,
            'type' => 'sibling_of',
            'status' => 'disputed',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);
        $linkedUser = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $linkedUser->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);
        $avatarUpload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $linkedUser->id,
            'purpose' => 'account_avatar',
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "users/{$linkedUser->id}/avatar.jpg",
            'canonical_mime_type' => 'image/jpeg',
        ]);
        $linkedUser->forceFill(['avatar_media_upload_id' => $avatarUpload->id])->save();
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $avatarOnly->id,
            'user_id' => $linkedUser->id,
            'created_by' => $owner->id,
        ]);
        $this->portraitPhoto($family, $owner, $child, PhotoVisibility::FamilySpace);
        $this->portraitPhoto($family, $owner, $indirect, PhotoVisibility::Private);

        $items = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people?limit=20")
            ->assertOk()->collect('data.items')->keyBy('id');
        $this->assertSame('child', $items[$child->id]['relationship_summary']['label']);
        $this->assertStringContainsString('thumbnail.v1.webp', $items[$child->id]['portrait_thumbnail_url']);
        $this->assertNull($items[$indirect->id]['relationship_summary']);
        $this->assertNull($items[$indirect->id]['portrait_thumbnail_url']);
        $this->assertNull($items[$disputed->id]['relationship_summary']);
        $this->assertNull($items[$avatarOnly->id]['portrait_thumbnail_url']);

        PersonAccountLink::query()->where('user_id', $viewer->id)->delete();
        $withoutLink = $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/people?q=child")
            ->assertOk();
        $withoutLink->assertJsonPath('data.items.0.relationship_summary', null);
    }

    public function test_soft_deleted_absorbed_people_are_excluded(): void
    {
        [$family, $viewer] = $this->family('people-tombstones');
        Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Survivor']);
        Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Absorbed'])->delete();

        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/people")
            ->assertOk()->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.preferred_name', 'Survivor');
    }

    public function test_card_enrichment_is_batched_instead_of_querying_per_person(): void
    {
        [$family, $viewer] = $this->family('people-batched');
        Person::factory()->count(20)->create(['family_space_id' => $family->id]);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($viewer)->getJson("/api/families/{$family->slug}/people?limit=20")
            ->assertOk()->assertJsonCount(20, 'data.items');

        $queries = collect(DB::getQueryLog())->pluck('query');
        $this->assertSame(1, $queries->filter(fn (string $query): bool => str_contains($query, 'from "people"'))->count());
        $this->assertLessThanOrEqual(1, $queries->filter(fn (string $query): bool => str_contains($query, 'from "person_relationships"'))->count());
        $this->assertLessThanOrEqual(1, $queries->filter(fn (string $query): bool => str_contains($query, 'from "media_variants"'))->count());
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug, ?User $user = null): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);
        $user ??= User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => FamilySpaceRole::Owner,
            'state' => MembershipState::Active,
        ]);

        return [$family, $user];
    }

    /** @return array{FamilySpace, User, User} */
    private function familyWithOwnerAndMember(string $slug): array
    {
        [$family, $owner] = $this->family($slug);
        $member = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ]);

        return [$family, $owner, $member];
    }

    private function portraitPhoto(
        FamilySpace $family,
        User $creator,
        Person $person,
        PhotoVisibility $visibility,
    ): void {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/media/{$person->id}.jpg",
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
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
        ]);
        $photo->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $creator->id,
            'resolved_by' => $creator->id,
            'resolved_at' => now(),
        ]);
    }
}
