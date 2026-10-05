<?php

namespace Tests\Feature;

use App\Enums\AlbumVisibility;
use App\Enums\DatePrecision;
use App\Enums\FaceAnalysisRunStatus;
use App\Enums\FaceIdentityAssignmentStatus;
use App\Enums\FaceObservationReviewDisposition;
use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Enums\RelationshipProposalAction;
use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipStatus;
use App\Enums\RelationshipType;
use App\Models\Album;
use App\Models\FaceAnalysisRun;
use App\Models\FaceIdentityAssignment;
use App\Models\FaceObservation;
use App\Models\FaceObservationReview;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonRelationship;
use App\Models\Photo;
use App\Models\RelationshipProposal;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SettingsOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_counts_and_archive_health_follow_canonical_viewer_visibility_and_authority(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'settings-overview']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $first = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'First']);
        $second = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Second']);
        $third = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Third']);
        $linkedOnly = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Linked only']);
        $absorbed = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Absorbed']);
        $foreignFamily = FamilySpace::factory()->create(['slug' => 'other-settings-overview']);
        $foreignOwner = $this->member($foreignFamily, FamilySpaceRole::Owner);
        Person::factory()->create(['family_space_id' => $foreignFamily->id]);

        $this->relationship($family, $owner, $first, $second, RelationshipStatus::Confirmed, RelationshipType::PartnerOf);
        $this->relationship($family, $owner, $first, $third, RelationshipStatus::Confirmed, RelationshipType::ParentOf);
        $this->relationship($family, $owner, $linkedOnly, $absorbed, RelationshipStatus::Confirmed, RelationshipType::SiblingOf);
        $absorbed->delete();
        $this->relationship($family, $owner, $first, $linkedOnly, RelationshipStatus::Disputed, RelationshipType::GuardianOf);
        RelationshipProposal::query()->create([
            'family_space_id' => $family->id,
            'action' => RelationshipProposalAction::Create,
            'subject_person_id' => $second->id,
            'related_person_id' => $linkedOnly->id,
            'type' => RelationshipType::SiblingOf,
            'status' => RelationshipProposalStatus::Pending,
            'proposed_by' => $viewer->id,
        ]);
        PersonAccountLink::query()->create([
            'family_space_id' => $family->id,
            'person_id' => $linkedOnly->id,
            'user_id' => User::factory()->create()->id,
            'created_by' => $owner->id,
        ]);

        $dated = $this->photo($family, $owner, PhotoVisibility::FamilySpace, 'Dated', [
            'historical_date' => '1984-06-01',
            'historical_date_precision' => DatePrecision::Month,
        ]);
        $undated = $this->photo($family, $owner, PhotoVisibility::FamilySpace, 'Undated', [
            'historical_date' => null,
            'historical_date_precision' => null,
            'created_at' => '1984-06-01 12:00:00',
        ]);
        $hidden = $this->photo($family, $owner, PhotoVisibility::Private, 'Hidden');
        $deleted = $this->photo($family, $owner, PhotoVisibility::FamilySpace, 'Deleted');
        $deleted->delete();
        $foreignPhoto = $this->photo($foreignFamily, $foreignOwner, PhotoVisibility::FamilySpace, 'Foreign');

        $visibleAlbum = $this->album($family, $owner, 'Visible', AlbumVisibility::FamilySpace);
        $this->album($family, $owner, 'Hidden', AlbumVisibility::Private);
        $this->album($family, $owner, 'Deleting', AlbumVisibility::FamilySpace, now());
        $this->album($foreignFamily, $foreignOwner, 'Foreign', AlbumVisibility::FamilySpace);

        Story::query()->create($this->storyAttributes($family, $owner, $dated));
        Story::query()->create([
            ...$this->storyAttributes($family, $owner, null),
            'album_id' => $visibleAlbum->id,
        ]);
        Story::query()->create($this->storyAttributes($family, $owner, $hidden));
        $deletedStory = Story::query()->create($this->storyAttributes($family, $owner, $undated));
        $deletedStory->delete();
        Story::query()->create($this->storyAttributes($foreignFamily, $foreignOwner, $foreignPhoto));

        $run = $this->analysisRun($dated);
        $observations = collect(range(0, 6))->map(
            fn (int $index): FaceObservation => $this->observation($family, $run, $index),
        );
        $this->assignment($family, $observations[1], $first, FaceIdentityAssignmentStatus::Pending, 'automatic_suggestion');
        $this->assignment($family, $observations[2], $first, FaceIdentityAssignmentStatus::Pending, 'human');
        $this->assignment($family, $observations[3], $first, FaceIdentityAssignmentStatus::Approved, 'human');
        FaceObservationReview::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observations[4]->id,
            'disposition' => FaceObservationReviewDisposition::LeftUnidentified,
            'reviewed_by' => $owner->id,
            'reviewed_at' => now(),
        ]);
        $this->assignment($family, $observations[5], $first, FaceIdentityAssignmentStatus::Rejected, 'human');
        $this->assignment($family, $observations[6], $first, FaceIdentityAssignmentStatus::Superseded, 'human');
        $this->observation($family, $this->analysisRun($hidden), 0);
        $this->observation($foreignFamily, $this->analysisRun($foreignPhoto), 0);

        $response = $this->actingAs($viewer)
            ->getJson('/api/families/settings-overview/settings/overview')
            ->assertOk();

        $response->assertExactJson(['data' => [
            'counts' => ['people' => 4, 'photos' => 2, 'albums' => 1, 'stories' => 2],
            'archive_health' => [
                'photos_dated' => ['numerator' => 1, 'denominator' => 2, 'percentage' => 50],
                'faces_identified' => ['numerator' => 1, 'denominator' => 7, 'percentage' => 14.29],
                'people_connected' => ['numerator' => 3, 'denominator' => 4, 'percentage' => 75],
            ],
        ]]);
    }

    public function test_unknown_or_missing_historical_date_is_not_dated_and_zero_denominators_are_null(): void
    {
        $emptyFamily = FamilySpace::factory()->create(['slug' => 'empty-overview']);
        $viewer = $this->member($emptyFamily, FamilySpaceRole::Member);

        $this->actingAs($viewer)->getJson('/api/families/empty-overview/settings/overview')
            ->assertOk()
            ->assertJsonPath('data.counts.people', 0)
            ->assertJsonPath('data.counts.photos', 0)
            ->assertJsonPath('data.archive_health.photos_dated.percentage', null)
            ->assertJsonPath('data.archive_health.faces_identified.percentage', null)
            ->assertJsonPath('data.archive_health.people_connected.percentage', null);

        $owner = $this->member($emptyFamily, FamilySpaceRole::Owner);
        $this->photo($emptyFamily, $owner, PhotoVisibility::FamilySpace, 'Unknown', [
            'historical_date' => null,
            'historical_date_precision' => DatePrecision::Unknown,
        ]);
        $this->actingAs($viewer)->getJson('/api/families/empty-overview/settings/overview')
            ->assertOk()
            ->assertJsonPath('data.archive_health.photos_dated.numerator', 0)
            ->assertJsonPath('data.archive_health.photos_dated.denominator', 1)
            ->assertJsonPath('data.archive_health.photos_dated.percentage', 0);
    }

    public function test_overview_requires_current_member_level_directory_access(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'overview-authorization']);
        $member = $this->member($family, FamilySpaceRole::Member);
        $contributor = $this->member($family, FamilySpaceRole::Contributor);
        $guest = $this->member($family, FamilySpaceRole::Guest);

        $this->actingAs($member)->getJson('/api/families/overview-authorization/settings/overview')->assertOk();
        $this->actingAs($contributor)->getJson('/api/families/overview-authorization/settings/overview')->assertForbidden();
        $this->actingAs($guest)->getJson('/api/families/overview-authorization/settings/overview')->assertForbidden();
    }

    public function test_read_model_executes_as_one_bounded_aggregate_query(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'overview-query-count']);
        $viewer = $this->member($family, FamilySpaceRole::Member);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($viewer)->getJson('/api/families/overview-query-count/settings/overview')->assertOk();
        $aggregateQueries = collect(DB::getQueryLog())->filter(
            fn (array $query): bool => str_contains($query['query'], 'people_metrics'),
        );
        DB::disableQueryLog();

        $this->assertCount(1, $aggregateQueries, 'Settings Overview must remain one bounded aggregate SQL query.');
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

    /** @param array<string, mixed> $attributes */
    private function photo(
        FamilySpace $family,
        User $creator,
        PhotoVisibility $visibility,
        string $caption,
        array $attributes = [],
    ): Photo {
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $creator->id,
            'state' => MediaUploadState::Ready,
            'canonical_object_key' => "families/{$family->id}/canonical/".Str::ulid().'.webp',
            'canonical_mime_type' => 'image/webp',
            'canonical_sha256' => str_repeat('a', 64),
        ]);

        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
            'caption' => $caption,
            ...$attributes,
        ]);
    }

    private function album(
        FamilySpace $family,
        User $creator,
        string $name,
        AlbumVisibility $visibility,
        mixed $deletingAt = null,
    ): Album {
        return Album::query()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'name' => $name,
            'visibility' => $visibility,
            'deleting_at' => $deletingAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function storyAttributes(FamilySpace $family, User $author, ?Photo $photo): array
    {
        return [
            'family_space_id' => $family->id,
            'author_id' => $author->id,
            'photo_id' => $photo?->id,
            'body' => ['type' => 'doc', 'content' => []],
            'body_plain_text' => 'A first-class Story.',
        ];
    }

    private function relationship(
        FamilySpace $family,
        User $actor,
        Person $subject,
        Person $related,
        RelationshipStatus $status,
        RelationshipType $type,
    ): PersonRelationship {
        return PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $subject->id,
            'related_person_id' => $related->id,
            'type' => $type,
            'status' => $status,
            'created_by' => $actor->id,
        ]);
    }

    private function analysisRun(Photo $photo): FaceAnalysisRun
    {
        $identity = config('image-analysis.identity');

        return FaceAnalysisRun::query()->create([
            'family_space_id' => $photo->family_space_id,
            'media_upload_id' => $photo->media_upload_id,
            'canonical_sha256' => str_repeat('a', 64),
            'contract_version' => '1',
            'provider' => $identity['provider'],
            'model_identifier' => $identity['model_identifier'],
            'model_weight_checksum' => $identity['model_weight_checksum'],
            'config_hash' => $identity['config_hash'],
            'status' => FaceAnalysisRunStatus::Succeeded,
            'attempt_count' => 1,
            'succeeded_at' => now(),
        ]);
    }

    private function observation(FamilySpace $family, FaceAnalysisRun $run, int $index): FaceObservation
    {
        return FaceObservation::query()->create([
            'family_space_id' => $family->id,
            'face_analysis_run_id' => $run->id,
            'face_index' => $index,
            'bounds_x' => 10 + $index,
            'bounds_y' => 10 + $index,
            'bounds_width' => 20,
            'bounds_height' => 20,
            'landmarks' => [],
            'landmark_scheme' => '5-point',
            'detection_confidence' => 1,
            'embedding' => pack('g*', 1.0, 0.0, 0.0),
            'embedding_dimension' => 3,
            'embedding_dtype' => 'float32',
        ]);
    }

    private function assignment(
        FamilySpace $family,
        FaceObservation $observation,
        Person $person,
        FaceIdentityAssignmentStatus $status,
        string $source,
    ): FaceIdentityAssignment {
        return FaceIdentityAssignment::query()->create([
            'family_space_id' => $family->id,
            'face_observation_id' => $observation->id,
            'person_id' => $person->id,
            'proposal_source' => $source,
            'status' => $status,
        ]);
    }
}
