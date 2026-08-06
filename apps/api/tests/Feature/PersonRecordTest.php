<?php

namespace Tests\Feature;

use App\Enums\DatePrecision;
use App\Enums\FamilySpaceRole;
use App\Enums\PersonIdentityStatus;
use App\Enums\PersonProposalStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_creates_a_confirmed_living_or_deceased_person_with_uncertain_dates(): void
    {
        [$familySpace, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'people-family');

        $response = $this->actingAs($owner)->postJson('/api/families/people-family/people', [
            'preferred_name' => 'Joan Oliver',
            'alternate_names' => ['Joan Smith'],
            'birth_date' => ['precision' => 'year', 'value' => '1928'],
            'is_deceased' => true,
            'death_date' => ['precision' => 'unknown', 'value' => null],
            'biography' => 'Remembered by the whole family.',
        ])->assertCreated()
            ->assertJsonPath('data.identity_status', PersonIdentityStatus::Confirmed->value)
            ->assertJsonPath('data.birth_date.precision', DatePrecision::Year->value)
            ->assertJsonPath('data.birth_date.value', '1928')
            ->assertJsonPath('data.is_deceased', true)
            ->assertJsonPath('data.death_date.precision', DatePrecision::Unknown->value)
            ->assertJsonPath('data.death_date.value', null)
            ->assertJsonPath('data.permissions.can_update_authoritatively', true);

        $person = Person::query()->findOrFail($response->json('data.id'));
        $this->assertSame($familySpace->id, $person->family_space_id);
        $this->assertSame('1928-01-01', $person->birth_date?->format('Y-m-d'));
        $this->assertNull($person->death_date);
        $this->assertSame($owner->id, $person->confirmed_by);
        $this->assertDatabaseHas('audit_events', [
            'family_space_id' => $familySpace->id,
            'actor_user_id' => $owner->id,
            'action' => 'person.created',
            'subject_id' => $person->id,
        ]);
    }

    public function test_member_creation_is_provisional_and_authoritative_changes_require_review(): void
    {
        [$familySpace, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'review-family');
        $member = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
        ]);

        $created = $this->actingAs($member)->postJson('/api/families/review-family/people', [
            'preferred_name' => 'Grandad',
        ])->assertCreated()
            ->assertJsonPath('data.identity_status', PersonIdentityStatus::Provisional->value)
            ->assertJsonPath('data.permissions.can_update_authoritatively', false)
            ->json('data');

        $this->actingAs($member)
            ->patchJson("/api/families/review-family/people/{$created['id']}", [
                'preferred_name' => 'Grandfather',
            ])
            ->assertForbidden();

        $proposal = $this->actingAs($member)
            ->postJson("/api/families/review-family/people/{$created['id']}/proposals", [
                'preferred_name' => 'Grandfather',
                'birth_date' => ['precision' => 'decade', 'value' => '1920s'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', PersonProposalStatus::Pending->value)
            ->json('data');

        $this->assertSame('Grandad', Person::query()->findOrFail($created['id'])->preferred_name);

        $this->actingAs($member)
            ->getJson("/api/families/review-family/people/{$created['id']}/proposals")
            ->assertForbidden();
        $this->actingAs($owner)
            ->getJson("/api/families/review-family/people/{$created['id']}/proposals")
            ->assertOk()
            ->assertJsonPath('data.0.id', $proposal['id'])
            ->assertJsonPath('data.0.changes.preferred_name', 'Grandfather');

        $this->actingAs($owner)
            ->postJson("/api/families/review-family/people/{$created['id']}/proposals/{$proposal['id']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', PersonProposalStatus::Approved->value);

        $this->actingAs($owner)
            ->getJson("/api/families/review-family/people/{$created['id']}/proposals")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $person = Person::query()->findOrFail($created['id']);
        $this->assertSame('Grandfather', $person->preferred_name);
        $this->assertSame(PersonIdentityStatus::Confirmed, $person->identity_status);
        $this->assertSame(DatePrecision::Decade, $person->birth_date_precision);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.detail_proposed']);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.detail_proposal_approved']);
    }

    public function test_rejected_proposal_does_not_change_the_person(): void
    {
        [$familySpace, $administrator] = $this->familyWithRole(FamilySpaceRole::Administrator, 'rejection-family');
        $member = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $member->id,
            'role' => FamilySpaceRole::Member,
        ]);
        $person = Person::factory()->create([
            'family_space_id' => $familySpace->id,
            'preferred_name' => 'Original Name',
        ]);

        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/rejection-family/people/{$person->id}/proposals", [
                'preferred_name' => 'Rejected Name',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($administrator)
            ->postJson("/api/families/rejection-family/people/{$person->id}/proposals/{$proposalId}/reject")
            ->assertOk()
            ->assertJsonPath('data.status', PersonProposalStatus::Rejected->value);

        $this->assertSame('Original Name', $person->refresh()->preferred_name);
        $this->assertDatabaseHas('audit_events', ['action' => 'person.detail_proposal_rejected']);
    }

    public function test_person_directory_visibility_matches_the_phase_four_role_baseline(): void
    {
        [$familySpace, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'visibility-family');
        Person::factory()->create(['family_space_id' => $familySpace->id, 'preferred_name' => 'Visible Person']);

        foreach ([FamilySpaceRole::Owner, FamilySpaceRole::Administrator, FamilySpaceRole::Member] as $role) {
            $user = $role === FamilySpaceRole::Owner ? $owner : User::factory()->create();
            if ($role !== FamilySpaceRole::Owner) {
                FamilySpaceMembership::factory()->create([
                    'family_space_id' => $familySpace->id,
                    'user_id' => $user->id,
                    'role' => $role,
                ]);
            }

            $this->actingAs($user)
                ->getJson('/api/families/visibility-family/people')
                ->assertOk()
                ->assertJsonPath('data.0.preferred_name', 'Visible Person');
        }

        foreach ([FamilySpaceRole::Contributor, FamilySpaceRole::Guest] as $role) {
            $user = User::factory()->create();
            FamilySpaceMembership::factory()->create([
                'family_space_id' => $familySpace->id,
                'user_id' => $user->id,
                'role' => $role,
            ]);
            $this->actingAs($user)->getJson('/api/families/visibility-family/people')->assertForbidden();
        }
    }

    public function test_person_routes_fail_closed_across_family_spaces(): void
    {
        [, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'first-people-family');
        [$secondFamily] = $this->familyWithRole(FamilySpaceRole::Owner, 'second-people-family');
        $person = Person::factory()->create(['family_space_id' => $secondFamily->id]);

        $this->actingAs($owner)
            ->getJson("/api/families/first-people-family/people/{$person->id}")
            ->assertNotFound();
        $this->actingAs($owner)
            ->patchJson("/api/families/first-people-family/people/{$person->id}", [
                'preferred_name' => 'Cross-tenant write',
            ])
            ->assertNotFound();

        $this->assertNotSame('Cross-tenant write', $person->refresh()->preferred_name);
    }

    public function test_uncertain_date_validation_and_deceased_state_are_independent(): void
    {
        [, $owner] = $this->familyWithRole(FamilySpaceRole::Owner, 'dates-family');

        foreach ([
            ['precision' => 'exact', 'value' => '1980-02-31'],
            ['precision' => 'month', 'value' => '1980-13'],
            ['precision' => 'year', 'value' => '80'],
            ['precision' => 'decade', 'value' => '1985s'],
            ['precision' => 'unknown', 'value' => '1980'],
        ] as $birthDate) {
            $this->actingAs($owner)->postJson('/api/families/dates-family/people', [
                'preferred_name' => 'Invalid Date',
                'birth_date' => $birthDate,
            ])->assertUnprocessable()->assertJsonValidationErrors('birth_date.value');
        }

        $this->actingAs($owner)->postJson('/api/families/dates-family/people', [
            'preferred_name' => 'Living Person',
            'is_deceased' => false,
            'death_date' => ['precision' => 'year', 'value' => '2020'],
        ])->assertUnprocessable()->assertJsonValidationErrors('death_date');

        $this->actingAs($owner)->postJson('/api/families/dates-family/people', [
            'preferred_name' => 'Deceased Date Unknown',
            'is_deceased' => true,
            'death_date' => ['precision' => 'unknown', 'value' => null],
        ])->assertCreated()->assertJsonPath('data.is_deceased', true);
    }

    /** @return array{FamilySpace, User} */
    private function familyWithRole(FamilySpaceRole $role, string $slug): array
    {
        $familySpace = FamilySpace::factory()->create(['slug' => $slug]);
        $user = User::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        return [$familySpace, $user];
    }
}
