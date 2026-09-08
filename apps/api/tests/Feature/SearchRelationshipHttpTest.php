<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoComment;
use App\Models\PhotoReaction;
use App\Models\PhotoStory;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SearchRelationshipHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_people_search_is_not_invoked_or_disclosed_without_directory_access(): void
    {
        [$family, $owner, , $contributor, $guest] = $this->family();
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'David Archive',
            'alternate_names' => ['Dave'],
        ]);

        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/search?q=dave&group=people")
            ->assertOk()
            ->assertJsonPath('data.people.items.0.id', $person->id);

        $default = $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/search?q=dave")
            ->assertOk();
        $this->assertArrayNotHasKey('people', $default->json('data'));
        $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/search?q=dave&group=people")
            ->assertForbidden();
        $guestDefault = $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/search?q=dave")
            ->assertOk();
        $this->assertArrayNotHasKey('people', $guestDefault->json('data'));
        $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/search?q=dave&group=people")
            ->assertForbidden();
    }

    public function test_guest_event_search_uses_only_current_admissions(): void
    {
        [$family, $owner, , , $guest, $guestMembership] = $this->family();
        $visible = $this->event($family, $owner, 'Summer gathering');
        $hidden = $this->event($family, $owner, 'Summer secret');
        $expired = $this->event($family, $owner, 'Summer expired');
        $revoked = $this->event($family, $owner, 'Summer revoked');
        EventAdmission::query()->create([
            'family_space_id' => $family->id,
            'event_id' => $visible->id,
            'family_space_membership_id' => $guestMembership->id,
            'admitted_at' => now(),
        ]);
        EventAdmission::query()->create([
            'family_space_id' => $family->id,
            'event_id' => $expired->id,
            'family_space_membership_id' => $guestMembership->id,
            'admitted_at' => now()->subDays((int) config('events.admission_lifetime_days') + 1),
        ]);
        EventAdmission::query()->create([
            'family_space_id' => $family->id,
            'event_id' => $revoked->id,
            'family_space_membership_id' => $guestMembership->id,
            'admitted_at' => now(),
            'revoked_at' => now(),
            'revoked_by' => $owner->id,
        ]);

        $response = $this->actingAs($guest)
            ->getJson("/api/families/{$family->slug}/search?q=summer&group=events")
            ->assertOk()
            ->assertJsonCount(1, 'data.events.items')
            ->assertJsonPath('data.events.items.0.id', $visible->id);
        $this->assertStringNotContainsString($hidden->id, $response->getContent());
    }

    public function test_combined_person_event_and_date_filters_use_approved_intersection(): void
    {
        [$family, $owner, $member] = $this->family();
        $event = $this->event($family, $owner, 'Wedding');
        $first = Person::factory()->create(['family_space_id' => $family->id]);
        $second = Person::factory()->create(['family_space_id' => $family->id]);
        $match = $this->photo($family, $owner, 'Together', '1980-01-01', 'decade', $event->id);
        $pendingOnly = $this->photo($family, $owner, 'Not confirmed', '1985-01-01', 'exact', $event->id);
        $outside = $this->photo($family, $owner, 'Outside date', '2000-01-01', 'exact', $event->id);
        foreach ([[$match, $first, 'approved'], [$match, $second, 'approved'],
            [$pendingOnly, $first, 'approved'], [$pendingOnly, $second, 'pending'],
            [$outside, $first, 'approved'], [$outside, $second, 'approved']] as [$photo, $person, $status]) {
            $this->associate($photo, $person, $owner, $status);
        }
        $story = PhotoStory::query()->create([
            'family_space_id' => $family->id,
            'photo_id' => $match->id,
            'author_id' => $owner->id,
            'body' => 'Together at the wedding.',
        ]);

        $query = http_build_query([
            'person_ids' => [$first->id, $second->id],
            'event_id' => $event->id,
            'date_from' => '1985-01-01',
            'date_to' => '1985-12-31',
            'group' => 'photos',
        ]);
        $this->actingAs($member)->getJson("/api/families/{$family->slug}/search?{$query}")
            ->assertOk()->assertJsonCount(1, 'data.photos.items')
            ->assertJsonPath('data.photos.items.0.id', $match->id);
        $storyQuery = http_build_query([
            'person_ids' => [$first->id, $second->id],
            'event_id' => $event->id,
            'date_from' => '1985-01-01',
            'date_to' => '1985-12-31',
            'group' => 'stories',
        ]);
        $this->actingAs($member)->getJson("/api/families/{$family->slug}/search?{$storyQuery}")
            ->assertOk()->assertJsonCount(1, 'data.stories.items')
            ->assertJsonPath('data.stories.items.0.id', $story->id);
    }

    public function test_autocomplete_and_discovery_never_traverse_outside_visible_content(): void
    {
        [$family, $owner, $member, $contributor] = $this->family();
        $event = $this->event($family, $owner, 'Beach reunion');
        $album = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Beach album',
            'visibility' => AlbumVisibility::FamilySpace,
            'event_id' => $event->id,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'David']);
        $companion = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Susan']);
        $visible = $this->photo($family, $owner, 'Beach photograph', '1990-01-01', 'exact', $event->id);
        $hidden = $this->photo($family, $owner, 'Private photograph', '1990-01-01', 'exact', null, PhotoVisibility::Private);
        $hidden->update(['primary_event_id' => $event->id]);
        $owner->update(['name' => 'Beach Uploader']);
        $hiddenUploader = User::factory()->create(['name' => 'Secret Uploader']);
        MediaUpload::query()->whereKey($hidden->media_upload_id)->update(['user_id' => $hiddenUploader->id]);
        $album->photos()->attach($visible->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id,
        ]);
        $hiddenAlbum = Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => 'Secret album',
            'visibility' => AlbumVisibility::Selected,
            'event_id' => $event->id,
        ]);
        $hiddenAlbum->photos()->attach($visible->id, [
            'id' => (string) Str::ulid(), 'family_space_id' => $family->id, 'position' => 1, 'added_by' => $owner->id,
        ]);
        $this->associate($visible, $person, $owner, 'approved');
        $this->associate($visible, $companion, $owner, 'approved');
        $this->associate($hidden, $person, $owner, 'approved');
        PhotoStory::query()->create([
            'family_space_id' => $family->id, 'photo_id' => $visible->id,
            'author_id' => $owner->id, 'body' => 'A beach story.',
        ]);
        $hiddenStory = PhotoStory::query()->create([
            'family_space_id' => $family->id, 'photo_id' => $hidden->id,
            'author_id' => $owner->id, 'body' => 'Disclosure needle in a private Story.',
        ]);
        $comment = PhotoComment::query()->create([
            'family_space_id' => $family->id, 'photo_id' => $visible->id,
            'author_id' => $owner->id, 'body' => 'Comment-only disclosure needle.',
        ]);
        $reaction = PhotoReaction::query()->create([
            'family_space_id' => $family->id, 'photo_id' => $visible->id,
            'user_id' => $owner->id, 'reaction' => 'love',
        ]);
        $visibleTag = Tag::query()->create([
            'family_space_id' => $family->id, 'label' => 'Beach', 'normalized_label' => 'beach',
        ]);
        $hiddenTag = Tag::query()->create([
            'family_space_id' => $family->id, 'label' => 'Secret', 'normalized_label' => 'secret',
        ]);
        $visible->tags()->attach($visibleTag->id, ['family_space_id' => $family->id, 'added_by' => $owner->id]);
        $hidden->tags()->attach($hiddenTag->id, ['family_space_id' => $family->id, 'added_by' => $owner->id]);

        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=tags&prefix=Bea")
            ->assertOk()->assertJsonPath('data.0.id', $visibleTag->id);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=tags&prefix=Sec")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=albums&prefix=Bea")
            ->assertOk()->assertJsonPath('data.0.id', $album->id);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=events&prefix=Bea")
            ->assertOk()->assertJsonPath('data.0.id', $event->id);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=uploaders&prefix=Bea")
            ->assertOk()->assertJsonPath('data.0.id', (string) $owner->id);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=uploaders&prefix=Sec")
            ->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/search/suggestions?type=people&prefix=Dav")
            ->assertForbidden();
        $search = $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/search?q=disclosure")
            ->assertOk()->assertJsonCount(0, 'data.stories.items');
        $this->assertStringNotContainsString($hiddenStory->id, $search->getContent());
        $this->assertStringNotContainsString($comment->id, $search->getContent());
        $this->assertStringNotContainsString($reaction->id, $search->getContent());

        $discovery = $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/discover/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.related.photos.0.id', $visible->id)
            ->assertJsonPath('data.related.albums.0.id', $album->id)
            ->assertJsonPath('data.related.events.0.id', $event->id)
            ->assertJsonPath('data.related.people.0.id', $companion->id);
        $this->assertStringNotContainsString($hidden->id, $discovery->getContent());
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/discover/photos/{$visible->id}")
            ->assertOk()
            ->assertJsonPath('data.related.albums.0.id', $album->id)
            ->assertJsonCount(1, 'data.related.albums');
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/discover/albums/{$album->id}")
            ->assertOk()
            ->assertJsonPath('data.related.photos.0.id', $visible->id);
        $this->actingAs($member)
            ->getJson("/api/families/{$family->slug}/discover/events/{$event->id}")
            ->assertOk()
            ->assertJsonPath('data.related.photos.0.id', $visible->id)
            ->assertJsonCount(1, 'data.related.photos')
            ->assertJsonCount(1, 'data.related.albums');
        $this->actingAs($contributor)
            ->getJson("/api/families/{$family->slug}/discover/people/{$person->id}")
            ->assertForbidden();
    }

    /** @return array{FamilySpace, User, User, User, User, FamilySpaceMembership} */
    private function family(): array
    {
        $family = FamilySpace::factory()->create(['slug' => 'relationship-search']);
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $contributor = User::factory()->create();
        $guest = User::factory()->create();
        $guestMembership = null;
        foreach ([[$owner, FamilySpaceRole::Owner], [$member, FamilySpaceRole::Member],
            [$contributor, FamilySpaceRole::Contributor], [$guest, FamilySpaceRole::Guest]] as [$user, $role]) {
            $membership = FamilySpaceMembership::factory()->create([
                'family_space_id' => $family->id,
                'user_id' => $user->id,
                'role' => $role,
                'state' => MembershipState::Active,
            ]);
            if ($role === FamilySpaceRole::Guest) {
                $guestMembership = $membership;
            }
        }

        return [$family, $owner, $member, $contributor, $guest, $guestMembership];
    }

    private function event(FamilySpace $family, User $owner, string $name): FamilyEvent
    {
        return FamilyEvent::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => $name,
            'starts_on' => '1990-01-01',
        ]);
    }

    private function photo(
        FamilySpace $family,
        User $owner,
        string $caption,
        string $date,
        string $precision,
        ?string $eventId,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
    ): Photo {
        $upload = MediaUpload::factory()->create(['family_space_id' => $family->id, 'user_id' => $owner->id]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
            'caption' => $caption,
            'visibility' => $visibility,
            'historical_date' => $date,
            'historical_date_precision' => $precision,
            'primary_event_id' => $eventId,
        ]);
    }

    private function associate(Photo $photo, Person $person, User $actor, string $status): void
    {
        $photo->photoPeople()->create([
            'family_space_id' => $photo->family_space_id,
            'person_id' => $person->id,
            'proposal_source' => 'human',
            'status' => $status,
            'proposed_by' => $actor->id,
            'resolved_by' => $status === 'approved' ? $actor->id : null,
            'resolved_at' => $status === 'approved' ? now() : null,
        ]);
    }
}
