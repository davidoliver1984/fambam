<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SavedSearchHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_is_creator_private_and_person_ids_are_stored_relationally(): void
    {
        [$family, $owner, $member] = $this->family();
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'David',
        ]);

        $saved = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/saved-searches", [
            'name' => 'David memories',
            'filters' => ['q' => 'holiday', 'person_ids' => [$person->id]],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'David memories')
            ->assertJsonPath('data.filters.schema_version', 1)
            ->assertJsonPath('data.filters.person_ids.0', $person->id)
            ->json('data.id');

        $stored = (string) $this->getConnection()->table('saved_searches')->where('id', $saved)->value('filters');
        $this->assertStringNotContainsString($person->id, $stored);
        $this->assertDatabaseHas('saved_search_people', [
            'saved_search_id' => $saved,
            'family_space_id' => $family->id,
            'person_id' => $person->id,
        ]);

        $this->actingAs($member)->getJson("/api/families/{$family->slug}/saved-searches")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/saved-searches/{$saved}/results")
            ->assertNotFound();

        $this->actingAs($owner)->putJson("/api/families/{$family->slug}/saved-searches/{$saved}", [
            'name' => 'Renamed search',
            'filters' => ['q' => 'summer'],
        ])->assertOk()->assertJsonPath('data.name', 'Renamed search')
            ->assertJsonPath('data.filters.q', 'summer');
        $this->assertDatabaseMissing('saved_search_people', ['saved_search_id' => $saved]);
        $this->actingAs($owner)
            ->deleteJson("/api/families/{$family->slug}/saved-searches/{$saved}")
            ->assertNoContent();
        $this->assertDatabaseMissing('saved_searches', ['id' => $saved]);
    }

    public function test_execution_silently_revalidates_deleted_references_against_current_visibility(): void
    {
        [$family, $owner] = $this->family();
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Summer event',
        ]);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
            'caption' => 'Summer memory',
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Summer album',
            'visibility' => AlbumVisibility::FamilySpace,
            'event_id' => $event->id,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
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
        ]);
        $tag = Tag::query()->create([
            'family_space_id' => $family->id,
            'label' => 'Holiday',
            'normalized_label' => 'holiday',
            'created_by' => $owner->id,
        ]);
        $photo->tags()->attach($tag->id, [
            'family_space_id' => $family->id,
            'added_by' => $owner->id,
            'created_at' => now(),
        ]);

        $saved = $this->actingAs($owner)->postJson("/api/families/{$family->slug}/saved-searches", [
            'name' => 'Summer search',
            'filters' => [
                'q' => 'summer',
                'tag_id' => $tag->id,
                'event_id' => $event->id,
                'album_id' => $album->id,
                'uploaded_by' => $owner->id,
                'visibility' => 'family_space',
                'person_ids' => [$person->id],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.filters.album_id', $album->id)
            ->assertJsonPath('data.filters.uploaded_by', $owner->id)
            ->assertJsonPath('data.filters.visibility', 'family_space')
            ->json('data.id');

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/saved-searches/{$saved}/results?group=photos")
            ->assertOk()->assertJsonPath('data.photos.items.0.id', $photo->id);

        $person->delete();
        $event->delete();
        $photo->tags()->detach($tag->id);
        $album->delete();

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/saved-searches/{$saved}/results?group=photos")
            ->assertOk()
            ->assertJsonCount(1, 'data.photos.items')
            ->assertJsonPath('data.photos.items.0.id', $photo->id);
        $this->actingAs($owner)->getJson("/api/families/{$family->slug}/saved-searches")
            ->assertOk()
            ->assertJsonMissingPath('data.0.filters.event_id')
            ->assertJsonMissingPath('data.0.filters.album_id')
            ->assertJsonMissingPath('data.0.filters.tag_id')
            ->assertJsonPath('data.0.filters.uploaded_by', $owner->id);
    }

    /** @return array{FamilySpace, User, User} */
    private function family(): array
    {
        $family = FamilySpace::factory()->create(['slug' => 'saved-search-family']);
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
}
