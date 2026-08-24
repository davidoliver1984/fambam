<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PersonProposalStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\PhotoPerson;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoFamilyMetadataTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_confirms_every_uncertain_date_precision_without_using_exif(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'photo-dates');
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $owner->id,
            'state' => MediaUploadState::Ready,
            'exif_capture_timestamp' => '2026:08:24 12:00:00',
            'gps_latitude' => '51.5074000',
            'gps_longitude' => '-0.1278000',
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $owner->id,
        ]);
        $dates = [
            ['precision' => 'exact', 'value' => '1987-06-14'],
            ['precision' => 'month', 'value' => '1987-06'],
            ['precision' => 'year', 'value' => '1987'],
            ['precision' => 'decade', 'value' => '1980s'],
            ['precision' => 'approximate', 'value' => '1987-06-14'],
            ['precision' => 'unknown', 'value' => null],
        ];

        foreach ($dates as $date) {
            $this->actingAs($owner)
                ->postJson("/api/families/photo-dates/photos/{$photo->id}/metadata-proposals", [
                    'field' => 'historical_date',
                    'date' => $date,
                ])
                ->assertCreated()
                ->assertJsonPath('data.status', PersonProposalStatus::Approved->value)
                ->assertJsonPath('data.date.precision', $date['precision'])
                ->assertJsonPath('data.date.value', $date['value']);
        }

        $photo->refresh();
        $this->assertSame('unknown', $photo->historical_date_precision->value);
        $this->assertNull($photo->historical_date);
        $this->assertSame('2026:08:24 12:00:00', $upload->refresh()->exif_capture_timestamp);
        $this->assertDatabaseHas('audit_events', ['action' => 'photo.metadata_confirmed']);
    }

    public function test_member_date_and_location_require_owner_confirmation_and_remain_separate_from_gps(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'photo-metadata');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $upload = MediaUpload::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $member->id,
            'state' => MediaUploadState::Ready,
            'gps_latitude' => '53.4808000',
            'gps_longitude' => '-2.2426000',
        ]);
        $photo = Photo::factory()->create([
            'family_space_id' => $family->id,
            'media_upload_id' => $upload->id,
            'created_by' => $member->id,
        ]);

        $dateProposal = $this->actingAs($member)
            ->postJson("/api/families/photo-metadata/photos/{$photo->id}/metadata-proposals", [
                'field' => 'historical_date',
                'date' => ['precision' => 'decade', 'value' => '1970s'],
            ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');
        $locationProposal = $this->actingAs($member)
            ->postJson("/api/families/photo-metadata/photos/{$photo->id}/metadata-proposals", [
                'field' => 'location',
                'location_description' => "Grandma's house",
            ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->assertNull($photo->refresh()->historical_date_precision);
        $this->assertNull($photo->location_description);
        $this->actingAs($owner)->getJson("/api/families/photo-metadata/photos/{$photo->id}/metadata-proposals")
            ->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs($owner)
            ->postJson("/api/families/photo-metadata/photos/{$photo->id}/metadata-proposals/{$dateProposal}/approve")
            ->assertOk();
        $this->actingAs($owner)
            ->postJson("/api/families/photo-metadata/photos/{$photo->id}/metadata-proposals/{$locationProposal}/approve")
            ->assertOk();

        $this->actingAs($member)->getJson("/api/families/photo-metadata/photos/{$photo->id}")
            ->assertOk()
            ->assertJsonPath('data.historical_date.precision', 'decade')
            ->assertJsonPath('data.historical_date.value', '1970s')
            ->assertJsonPath('data.location_description', "Grandma's house")
            ->assertJsonMissingPath('data.gps_latitude');
        $this->assertSame('53.4808000', $upload->refresh()->gps_latitude);
    }

    public function test_only_confirmed_photo_people_are_authoritative_and_rejected_history_is_retained(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'photo-people');
        $member = $this->addMember($family, FamilySpaceRole::Member);
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $member->id]);
        $first = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Alice']);
        $second = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Bob']);

        $firstProposal = $this->actingAs($member)
            ->postJson("/api/families/photo-people/photos/{$photo->id}/people", ['person_id' => $first->id])
            ->assertCreated()->assertJsonPath('data.proposal_source', 'human')->json('data.id');
        $secondProposal = $this->actingAs($member)
            ->postJson("/api/families/photo-people/photos/{$photo->id}/people", ['person_id' => $second->id])
            ->assertCreated()->json('data.id');

        $this->actingAs($member)->getJson("/api/families/photo-people/photos/{$photo->id}")
            ->assertOk()->assertJsonCount(0, 'data.people');
        $this->actingAs($owner)
            ->postJson("/api/families/photo-people/photos/{$photo->id}/people/{$firstProposal}/approve")
            ->assertOk();
        $this->actingAs($owner)
            ->postJson("/api/families/photo-people/photos/{$photo->id}/people/{$secondProposal}/reject")
            ->assertOk();

        $this->actingAs($member)->getJson("/api/families/photo-people/photos/{$photo->id}")
            ->assertOk()->assertJsonCount(1, 'data.people')
            ->assertJsonPath('data.people.0.person.preferred_name', 'Alice');
        $this->assertDatabaseHas('photo_people', [
            'id' => $secondProposal,
            'status' => PersonProposalStatus::Rejected->value,
        ]);
        $this->assertSame(2, PhotoPerson::query()->count());
    }

    public function test_family_metadata_validation_and_tenant_boundaries_fail_closed(): void
    {
        [$family, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'metadata-first');
        [$otherFamily, $otherOwner] = $this->familyWithRole(FamilySpaceRole::Owner, 'metadata-second');
        $photo = Photo::factory()->create(['family_space_id' => $family->id, 'created_by' => $owner->id]);
        $foreignPerson = Person::factory()->create(['family_space_id' => $otherFamily->id]);

        $this->actingAs($owner)
            ->postJson("/api/families/metadata-first/photos/{$photo->id}/metadata-proposals", [
                'field' => 'historical_date',
                'date' => ['precision' => 'decade', 'value' => '1985s'],
            ])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->actingAs($owner)
            ->postJson("/api/families/metadata-first/photos/{$photo->id}/people", ['person_id' => $foreignPerson->id])
            ->assertNotFound();
        $this->actingAs($otherOwner)
            ->getJson("/api/families/metadata-second/photos/{$photo->id}")
            ->assertNotFound();
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->addMember($family, $role)];
    }

    private function addMember(FamilySpace $family, FamilySpaceRole $role): User
    {
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $family->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return $user;
    }
}
