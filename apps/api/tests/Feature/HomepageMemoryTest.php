<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\EventStatus;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\PhotoStory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomepageMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_homepage_derives_person_memories_and_typed_story_context(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 12:00:00');
        $family = FamilySpace::factory()->create(['slug' => 'homepage-memories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'William Mercer',
            'created_by' => $owner->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'caption' => 'At the seaside',
        ]);
        PhotoPerson::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);
        $story = PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'author_id' => $owner->id,
            'body' => 'William always remembered how cold the water was.',
        ]);
        $event = FamilyEvent::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Seaside holiday',
            'status' => EventStatus::Completed,
        ]);
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Summer memories',
            'visibility' => AlbumVisibility::FamilySpace,
            'event_id' => $event->id,
        ]);
        $album->photos()->attach($photo->id, [
            'id' => (string) Str::ulid(),
            'family_space_id' => $family->id,
            'position' => 1,
            'added_by' => $owner->id,
        ]);

        $this->actingAs($owner)
            ->getJson('/api/families/homepage-memories/memories/homepage')
            ->assertOk()
            ->assertJsonPath('data.recent_days', 30)
            ->assertJsonPath('data.people.0.person_id', $person->id)
            ->assertJsonPath('data.people.0.memory_count', 4)
            ->assertJsonPath('data.stories.0.id', $story->id)
            ->assertJsonPath('data.stories.0.photo_id', $photo->id)
            ->assertJsonPath('data.stories.0.media_upload_id', $photo->media_upload_id)
            ->assertJsonPath('data.stories.0.author.name', $owner->name)
            ->assertJsonPath('data.stories.0.people.0.id', $person->id)
            ->assertJsonPath('data.stories.0.albums.0.id', $album->id)
            ->assertJsonPath('data.stories.0.events.0.id', $event->id);
    }

    public function test_homepage_excludes_unauthorized_suppressed_stale_and_unapproved_memories(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 12:00:00');
        $family = FamilySpace::factory()->create(['slug' => 'bounded-memories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);

        $visible = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $visibleStory = $this->story($family, $visible, $owner, 'Visible recent Story');
        $this->association($family, $visible, $person, $owner, 'approved');

        $suppressed = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'do_not_resurface' => true,
        ]);
        $this->story($family, $suppressed, $owner, 'Suppressed Story');
        $this->association($family, $suppressed, $person, $owner, 'approved');

        $private = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        $this->story($family, $private, $owner, 'Private Story');
        $this->association($family, $private, $person, $owner, 'approved');

        $pendingPerson = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $this->association($family, $visible, $pendingPerson, $owner, 'pending');
        $stale = $this->story($family, $visible, $owner, 'Stale Story');
        $stale->forceFill(['created_at' => now()->subDays(31), 'updated_at' => now()->subDays(31)])->save();

        $response = $this->actingAs($viewer)
            ->getJson('/api/families/bounded-memories/memories/homepage')
            ->assertOk()
            ->assertJsonCount(1, 'data.people')
            ->assertJsonCount(1, 'data.stories')
            ->assertJsonPath('data.stories.0.id', $visibleStory->id);

        $response
            ->assertJsonMissing(['excerpt' => 'Suppressed Story'])
            ->assertJsonMissing(['excerpt' => 'Private Story'])
            ->assertJsonMissing(['excerpt' => 'Stale Story'])
            ->assertJsonMissing(['person_id' => $pendingPerson->id]);
    }

    public function test_contributor_receives_visible_stories_without_people_directory_disclosure(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'contributor-homepage']);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'created_by' => $contributor->id]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $contributor->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        $this->association($family, $photo, $person, $contributor, 'approved');
        $story = $this->story($family, $photo, $contributor, 'Contributor Story');

        $this->actingAs($contributor)
            ->getJson('/api/families/contributor-homepage/memories/homepage')
            ->assertOk()
            ->assertJsonCount(0, 'data.people')
            ->assertJsonPath('data.stories.0.id', $story->id)
            ->assertJsonCount(0, 'data.stories.0.people');
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

    private function story(FamilySpace $family, Photo $photo, User $author, string $body): PhotoStory
    {
        return PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'author_id' => $author->id,
            'body' => $body,
        ]);
    }

    private function association(
        FamilySpace $family,
        Photo $photo,
        Person $person,
        User $actor,
        string $status,
    ): PhotoPerson {
        return PhotoPerson::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $photo->id,
            'person_id' => $person->id,
            'status' => $status,
            'proposed_by' => $actor->id,
            ...($status === 'approved' ? ['resolved_by' => $actor->id, 'resolved_at' => now()] : []),
        ]);
    }
}
