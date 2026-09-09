<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\FamilyActivityType;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\FamilyActivity;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\Photo;
use App\Models\User;
use App\Services\FamilyActivityRecorder;
use App\Services\PersonMergeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class FamilyActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_domain_mutations_write_atomic_family_activity_and_resolve_actor_person(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-family']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $person = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'David']);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id, 'person_id' => $person->id,
            'user_id' => $owner->id, 'created_by' => $owner->id,
        ]);

        $albumId = $this->actingAs($owner)->postJson('/api/families/activity-family/albums', [
            'name' => 'Family days', 'visibility' => AlbumVisibility::FamilySpace->value,
        ])->assertCreated()->json('data.id');

        $activity = FamilyActivity::query()->sole();
        $this->assertSame(FamilyActivityType::AlbumCreated, $activity->action_type);
        $this->assertSame($albumId, $activity->subject_album_id);
        $this->assertSame($person->id, $activity->actor_person_id);

        $this->actingAs($owner)->getJson('/api/families/activity-family/activities/recent')
            ->assertOk()
            ->assertJsonPath('data.0.actor.name', 'David')
            ->assertJsonPath('data.0.subject.label', 'Family days');
    }

    public function test_each_accepted_activity_taxonomy_write_path_records_one_fact(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-taxonomy']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $albumId = $this->actingAs($owner)->postJson('/api/families/activity-taxonomy/albums', [
            'name' => 'Taxonomy album', 'visibility' => AlbumVisibility::FamilySpace->value,
        ])->assertCreated()->json('data.id');
        $this->actingAs($owner)->postJson('/api/families/activity-taxonomy/events', [
            'name' => 'Taxonomy event',
        ])->assertCreated();
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $this->actingAs($owner)->postJson("/api/families/activity-taxonomy/albums/{$albumId}/photos", [
            'photo_id' => $photo->id,
        ])->assertCreated();
        $this->actingAs($owner)->postJson("/api/families/activity-taxonomy/photos/{$photo->id}/stories", [
            'body' => 'A family story.',
        ])->assertCreated();
        $person = Person::factory()->create(['family_space_id' => $family->id]);
        $this->actingAs($owner)->postJson("/api/families/activity-taxonomy/photos/{$photo->id}/people", [
            'person_id' => $person->id,
        ])->assertCreated();

        $this->assertSame([
            FamilyActivityType::AlbumCreated->value,
            FamilyActivityType::EventCreated->value,
            FamilyActivityType::PersonIdentityConfirmed->value,
            FamilyActivityType::PhotosAddedToAlbum->value,
            FamilyActivityType::StoryAdded->value,
        ], FamilyActivity::query()->pluck('action_type')
            ->map(fn (FamilyActivityType $type): string => $type->value)
            ->sort()->values()->all());
    }

    public function test_batch_grouping_counts_only_currently_visible_photos(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-grouping']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $album = Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Archive', 'visibility' => AlbumVisibility::FamilySpace,
        ]);
        $visible = Photo::factory()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $hidden = Photo::factory()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'visibility' => PhotoVisibility::Private,
        ]);
        $recorder = app(FamilyActivityRecorder::class);
        foreach ([$visible, $hidden] as $photo) {
            $recorder->record(
                $family->id,
                $owner->id,
                FamilyActivityType::PhotosAddedToAlbum,
                subjectAlbumId: $album->id,
                contributionBatchId: '01M30000000000000000000000',
                photoIds: [$photo->id],
            );
        }

        $this->actingAs($member)->getJson('/api/families/activity-grouping/activities/recent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.photo_count', 1)
            ->assertJsonPath('data.0.photo_ids.0', $visible->id);
    }

    public function test_pending_human_identity_proposal_does_not_create_family_activity(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-pending-identity']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$member] = $this->member($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $owner->id,
            'visibility' => PhotoVisibility::FamilySpace,
        ]);
        $person = Person::factory()->create(['family_space_id' => $family->id]);

        $this->actingAs($member)->postJson("/api/families/activity-pending-identity/photos/{$photo->id}/people", [
            'person_id' => $person->id,
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseCount('family_activities', 0);
    }

    public function test_contributor_sees_granted_album_activity_but_not_family_event_activity(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-contributor']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        [$contributor, $membership] = $this->member($family, FamilySpaceRole::Contributor);
        $album = Album::query()->create([
            'family_space_id' => $family->id, 'created_by' => $owner->id,
            'name' => 'Shared album', 'visibility' => AlbumVisibility::Selected,
        ]);
        AlbumGrant::query()->create([
            'family_space_id' => $family->id, 'album_id' => $album->id,
            'family_space_membership_id' => $membership->id,
            'can_view' => true, 'can_contribute' => false, 'granted_by' => $owner->id,
        ]);
        app(FamilyActivityRecorder::class)->record(
            $family->id,
            $owner->id,
            FamilyActivityType::AlbumCreated,
            subjectAlbumId: $album->id,
        );
        $this->actingAs($owner)->postJson('/api/families/activity-contributor/events', [
            'name' => 'Private planning',
        ])->assertCreated()->json('data.id');

        $this->actingAs($contributor)
            ->getJson('/api/families/activity-contributor/activities/recent')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.subject.id', $album->id);
    }

    public function test_person_merge_repoints_activity_identity_references(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'activity-person-merge']);
        [$owner] = $this->member($family, FamilySpaceRole::Owner);
        $absorbed = Person::factory()->create(['family_space_id' => $family->id]);
        $survivor = Person::factory()->create(['family_space_id' => $family->id]);
        $activity = FamilyActivity::query()->create([
            'family_space_id' => $family->id,
            'actor_user_id' => $owner->id,
            'actor_person_id' => $absorbed->id,
            'action_type' => FamilyActivityType::PersonIdentityConfirmed,
            'subject_person_id' => $absorbed->id,
            'photo_ids' => [],
            'created_at' => now(),
        ]);

        app(PersonMergeManager::class)->merge(
            $absorbed,
            $survivor,
            null,
            $owner,
            Request::create('/people/merge', 'POST'),
        );

        $this->assertSame($survivor->id, $activity->refresh()->actor_person_id);
        $this->assertSame($survivor->id, $activity->subject_person_id);
    }

    /** @return array{User, FamilySpaceMembership} */
    private function member(FamilySpace $family, FamilySpaceRole $role): array
    {
        $user = User::factory()->create();
        $membership = FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
            'state' => MembershipState::Active,
        ]);

        return [$user, $membership];
    }
}
