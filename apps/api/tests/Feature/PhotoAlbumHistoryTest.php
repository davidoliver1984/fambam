<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\PhotoVisibility;
use App\Media\MediaObjectStorage;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\AuditEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhotoAlbumHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(MediaObjectStorage::class, $this->createStub(MediaObjectStorage::class));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_photo_detail_album_history_serializes_additions_removals_actors_and_current_state(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'photo-album-history']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner, 'David Mercer');
        [$administrator] = $this->member($family, FamilySpaceRole::Administrator, 'Sarah Account');
        $person = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'David Mercer',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $person->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $current = $this->album($family, $owner, 'Blackpool, 1986');
        $historical = $this->album($family, $owner, 'Family portraits');

        CarbonImmutable::setTestNow('2026-09-12 10:00:00');
        $this->actingAs($owner)->postJson($this->photosPath($family, $current), [
            'photo_id' => $photo->id,
        ])->assertCreated();
        CarbonImmutable::setTestNow('2026-09-13 10:00:00');
        $this->actingAs($owner)->postJson($this->photosPath($family, $historical), [
            'photo_id' => $photo->id,
        ])->assertCreated();
        CarbonImmutable::setTestNow('2026-09-14 10:00:00');
        $this->actingAs($administrator)
            ->deleteJson($this->photosPath($family, $historical).'/'.$photo->id)
            ->assertNoContent();

        $response = $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/photos/{$photo->id}/album-history")
            ->assertOk()->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.event_type', 'removed')
            ->assertJsonPath('data.0.album.id', $historical->id)
            ->assertJsonPath('data.0.album.name', 'Family portraits')
            ->assertJsonPath('data.0.actor.display_name', 'Sarah Account')
            ->assertJsonPath('data.0.actor.person_id', null)
            ->assertJsonPath('data.0.actor.initials', 'SA')
            ->assertJsonPath('data.0.created_at', '2026-09-14T10:00:00+00:00')
            ->assertJsonPath('data.0.is_current', false)
            ->assertJsonPath('data.1.event_type', 'added')
            ->assertJsonPath('data.1.album.id', $historical->id)
            ->assertJsonPath('data.1.actor.display_name', 'David Mercer')
            ->assertJsonPath('data.1.actor.person_id', $person->id)
            ->assertJsonPath('data.1.created_at', '2026-09-13T10:00:00+00:00')
            ->assertJsonPath('data.1.is_current', false)
            ->assertJsonPath('data.2.album.id', $current->id)
            ->assertJsonPath('data.2.is_current', true);

        $this->assertArrayNotHasKey('metadata', $response->json('data.0'));
        $this->assertDatabaseHas('audit_events', [
            'action' => 'album.photo_removed',
            'metadata' => json_encode(['album_id' => $historical->id, 'photo_id' => $photo->id]),
        ]);
    }

    public function test_history_omits_inaccessible_albums_and_cross_family_audit_rows(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'history-authorisation']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner, 'Owner');
        [$viewer] = $this->member($family, FamilySpaceRole::Member, 'Viewer');
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $visible = $this->album($family, $owner, 'Visible', AlbumVisibility::FamilySpace);
        $private = $this->album($family, $owner, 'Owner only', AlbumVisibility::Private);
        foreach ([$visible, $private] as $album) {
            $this->actingAs($owner)->postJson($this->photosPath($family, $album), [
                'photo_id' => $photo->id,
            ])->assertCreated();
        }

        $otherFamily = FamilySpace::factory()->create(['slug' => 'other-history-family']);
        [$otherOwner] = $this->member($otherFamily, FamilySpaceRole::Owner, 'Outsider');
        $otherAlbum = $this->album($otherFamily, $otherOwner, 'Other family');
        $this->audit($otherFamily, $otherOwner, $otherAlbum->id, $photo->id);

        $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/photos/{$photo->id}/album-history")
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.album.id', $visible->id)
            ->assertJsonMissing(['name' => 'Owner only'])
            ->assertJsonMissing(['name' => 'Other family'])
            ->assertJsonMissing(['display_name' => 'Outsider']);
    }

    public function test_inaccessible_person_and_portrait_fall_back_to_the_actor_account(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'history-person-fallback']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner, 'Sarah Account');
        [$viewer, $viewerMembership] = $this->member($family, FamilySpaceRole::Contributor, 'Contributor');
        $actorPerson = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Sarah Mercer',
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $actorPerson->id,
            'user_id' => $owner->id,
            'created_by' => $owner->id,
        ]);
        $portrait = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        $portrait->photoPeople()->create([
            'family_space_id' => $family->id,
            'person_id' => $actorPerson->id,
            'proposal_source' => 'human',
            'status' => 'approved',
            'proposed_by' => $owner->id,
            'resolved_by' => $owner->id,
            'resolved_at' => now(),
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        $album = $this->album($family, $owner, 'Selected', AlbumVisibility::Selected);
        AlbumGrant::query()->create([
            'family_space_id' => $family->id,
            'album_id' => $album->id,
            'family_space_membership_id' => $viewerMembership->id,
            'can_view' => true,
            'can_contribute' => true,
            'granted_by' => $owner->id,
        ]);
        $this->actingAs($owner)->postJson($this->photosPath($family, $album), [
            'photo_id' => $photo->id,
            'confirm_visibility_widening' => true,
        ])->assertCreated();

        $this->actingAs($viewer)
            ->getJson("/api/families/{$family->slug}/photos/{$photo->id}/album-history")
            ->assertOk()
            ->assertJsonPath('data.0.actor.display_name', 'Sarah Account')
            ->assertJsonPath('data.0.actor.person_id', null)
            ->assertJsonPath('data.0.actor.initials', 'SA')
            ->assertJsonPath('data.0.actor.portrait_thumbnail_url', null);
    }

    public function test_history_query_count_does_not_grow_with_history_rows(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'history-query-count']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner, 'Owner');
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $album = $this->album($family, $owner, 'One Album');
        $this->audit($family, $owner, $album->id, $photo->id);

        DB::enableQueryLog();
        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/photos/{$photo->id}/album-history")
            ->assertOk();
        $oneRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        foreach (range(1, 20) as $index) {
            $this->audit($family, $owner, $album->id, $photo->id, $index % 2 === 0 ? 'added' : 'removed');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($owner)
            ->getJson("/api/families/{$family->slug}/photos/{$photo->id}/album-history")
            ->assertOk()->assertJsonCount(21, 'data');
        $manyRowQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame($oneRowQueries, $manyRowQueries);
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role, string $name): array
    {
        $user = User::factory()->create(['name' => $name]);
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$user, $membership];
    }

    private function album(
        FamilySpace $family,
        User $owner,
        string $name,
        AlbumVisibility $visibility = AlbumVisibility::FamilySpace,
    ): Album {
        return Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'name' => $name,
            'visibility' => $visibility,
        ]);
    }

    private function photosPath(FamilySpace $family, Album $album): string
    {
        return "/api/families/{$family->slug}/albums/{$album->id}/photos";
    }

    private function audit(
        FamilySpace $family,
        User $actor,
        string $albumId,
        string $photoId,
        string $eventType = 'added',
    ): void {
        AuditEvent::query()->insert([
            'family_space_id' => $family->id,
            'actor_user_id' => $actor->id,
            'correlation_id' => (string) Str::uuid(),
            'traceparent' => '00-'.str_repeat('1', 32).'-'.str_repeat('2', 16).'-01',
            'action' => "album.photo_{$eventType}",
            'subject_type' => (new AlbumPhoto)->getMorphClass(),
            'subject_id' => (string) Str::ulid(),
            'metadata' => json_encode(['album_id' => $albumId, 'photo_id' => $photoId]),
            'created_at' => now(),
        ]);
    }
}
