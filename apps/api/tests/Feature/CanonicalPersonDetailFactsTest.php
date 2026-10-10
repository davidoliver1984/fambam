<?php

namespace Tests\Feature;

use App\Enums\FamilySpaceRole;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalPersonDetailFactsTest extends TestCase
{
    use RefreshDatabase;

    public function test_quote_and_ordered_known_for_are_normalized_and_sparse_people_remain_sparse(): void
    {
        [$family, $owner] = $this->family('canonical-person-facts');

        $created = $this->actingAs($owner)->postJson('/api/families/canonical-person-facts/people', [
            'preferred_name' => 'William Mercer',
            'profile_quote' => '  Keep every version of the story.  ',
            'profile_quote_attribution' => '  Sarah Mercer  ',
            'known_for' => ['  Sunday roasts  ', 'Blackpool holidays', 'Never missing a match'],
        ])->assertCreated()
            ->assertJsonPath('data.profile_quote', 'Keep every version of the story.')
            ->assertJsonPath('data.profile_quote_attribution', 'Sarah Mercer')
            ->assertJsonPath('data.known_for.0', 'Sunday roasts')
            ->assertJsonPath('data.known_for.2', 'Never missing a match')
            ->assertJsonPath('data.relationships', [])
            ->json('data');

        $this->assertSame(
            ['Sunday roasts', 'Blackpool holidays', 'Never missing a match'],
            Person::query()->findOrFail($created['id'])->knownFor()->pluck('label')->all(),
        );

        $this->actingAs($owner)->patchJson("/api/families/canonical-person-facts/people/{$created['id']}", [
            'profile_quote' => '  A quote without an attribution. ',
            'profile_quote_attribution' => '   ',
            'known_for' => ['Blackpool holidays', 'Sunday roasts'],
        ])->assertOk()
            ->assertJsonPath('data.profile_quote', 'A quote without an attribution.')
            ->assertJsonPath('data.profile_quote_attribution', null)
            ->assertJsonPath('data.known_for.0', 'Blackpool holidays')
            ->assertJsonPath('data.known_for.1', 'Sunday roasts');

        $this->actingAs($owner)->patchJson("/api/families/canonical-person-facts/people/{$created['id']}", [
            'profile_quote' => '   ',
            'known_for' => ['Sunday roasts', ' sunday roasts '],
        ])->assertUnprocessable()->assertJsonValidationErrors('known_for.1');

        $sparse = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Alex Mercer']);
        $this->actingAs($owner)->getJson("/api/families/canonical-person-facts/people/{$sparse->id}")
            ->assertOk()
            ->assertJsonPath('data.profile_quote', null)
            ->assertJsonPath('data.profile_quote_attribution', null)
            ->assertJsonPath('data.known_for', [])
            ->assertJsonPath('data.relationships', []);
    }

    public function test_member_person_facts_remain_proposed_until_owner_approval(): void
    {
        [$family, $owner] = $this->family('proposed-person-facts');
        $member = $this->member($family, FamilySpaceRole::Member);
        $person = Person::factory()->create(['family_space_id' => $family->id]);

        $this->actingAs($member)->patchJson("/api/families/proposed-person-facts/people/{$person->id}", [
            'profile_quote' => 'Unapproved',
            'known_for' => ['Unapproved fact'],
        ])->assertForbidden();

        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/proposed-person-facts/people/{$person->id}/proposals", [
                'profile_quote' => '  A family quotation  ',
                'profile_quote_attribution' => '  Aunt May ',
                'known_for' => ['  Garden parties ', 'Boxing Day walks'],
            ])->assertCreated()
            ->assertJsonPath('data.changes.profile_quote', 'A family quotation')
            ->assertJsonPath('data.changes.known_for.0', 'Garden parties')
            ->json('data.id');

        $this->assertNull($person->refresh()->profile_quote);
        $this->assertDatabaseCount('person_known_for', 0);

        $this->actingAs($owner)
            ->postJson("/api/families/proposed-person-facts/people/{$person->id}/proposals/{$proposalId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->actingAs($owner)->getJson("/api/families/proposed-person-facts/people/{$person->id}")
            ->assertOk()
            ->assertJsonPath('data.profile_quote', 'A family quotation')
            ->assertJsonPath('data.profile_quote_attribution', 'Aunt May')
            ->assertJsonPath('data.known_for.0', 'Garden parties')
            ->assertJsonPath('data.known_for.1', 'Boxing Day walks');
    }

    public function test_relationship_start_dates_use_uncertain_date_semantics_and_existing_authority(): void
    {
        $this->travelTo('2026-10-10 12:34:56');
        [$family, $owner] = $this->family('relationship-chronology');
        $member = $this->member($family, FamilySpaceRole::Member);
        $william = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'William']);
        $margaret = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Margaret']);
        $friend = Person::factory()->create(['family_space_id' => $family->id, 'preferred_name' => 'Friend']);

        $this->actingAs($owner)->postJson("/api/families/relationship-chronology/people/{$william->id}/relationships", [
            'related_person_id' => $margaret->id,
            'type' => 'partner_of',
            'relationship_started_on' => ['precision' => 'year', 'value' => '1967'],
        ])->assertCreated()
            ->assertJsonPath('data.relationship_started_on.precision', 'year')
            ->assertJsonPath('data.relationship_started_on.value', '1967')
            ->assertJsonPath('data.created_at', '2026-10-10T12:34:56+00:00');

        $this->actingAs($owner)->postJson("/api/families/relationship-chronology/people/{$william->id}/relationships", [
            'related_person_id' => $friend->id,
            'type' => 'parent_of',
            'relationship_started_on' => ['precision' => 'month', 'value' => '2001-05'],
        ])->assertUnprocessable()->assertJsonValidationErrors('relationship');

        $proposalId = $this->actingAs($member)
            ->postJson("/api/families/relationship-chronology/people/{$william->id}/relationship-proposals", [
                'action' => 'create',
                'related_person_id' => $friend->id,
                'type' => 'close_family_friend_of',
                'relationship_started_on' => ['precision' => 'exact', 'value' => '2001-05-03'],
            ])->assertCreated()
            ->assertJsonPath('data.relationship_started_on.value', '2001-05-03')
            ->assertJsonPath('data.created_at', '2026-10-10T12:34:56+00:00')
            ->json('data.id');
        $this->assertDatabaseCount('person_relationships', 1);

        $this->actingAs($owner)
            ->postJson("/api/families/relationship-chronology/people/{$william->id}/relationship-proposals/{$proposalId}/approve")
            ->assertOk();

        $this->actingAs($owner)->getJson("/api/families/relationship-chronology/people/{$william->id}")
            ->assertOk()
            ->assertJsonCount(2, 'data.relationships')
            ->assertJsonPath('data.relationships.0.relationship_started_on.value', '1967')
            ->assertJsonPath('data.relationships.0.created_at', '2026-10-10T12:34:56+00:00')
            ->assertJsonPath('data.relationships.1.relationship_started_on.value', '2001-05-03')
            ->assertJsonPath('data.relationships.1.created_at', '2026-10-10T12:34:56+00:00');

        $this->travelBack();
    }

    public function test_merge_deduplicates_and_appends_known_for_and_reversal_restores_dates_and_rows(): void
    {
        [$family, $owner] = $this->family('canonical-fact-merge');
        $survivor = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'William Mercer',
            'profile_quote' => 'Survivor quote',
        ]);
        $absorbed = Person::factory()->create([
            'family_space_id' => $family->id,
            'preferred_name' => 'Bill Mercer',
            'profile_quote' => 'Absorbed quote',
        ]);
        $partner = Person::factory()->create(['family_space_id' => $family->id]);
        foreach ([[$survivor, 'Railway journeys', 0], [$absorbed, 'railway journeys', 0], [$absorbed, 'Storytelling', 1]] as [$person, $label, $position]) {
            $person->knownFor()->create([
                'family_space_id' => $family->id,
                'label' => $label,
                'position' => $position,
                'created_by' => $owner->id,
            ]);
        }
        $relationship = PersonRelationship::query()->create([
            'family_space_id' => $family->id,
            'subject_person_id' => $absorbed->id,
            'related_person_id' => $partner->id,
            'type' => 'partner_of',
            'status' => 'confirmed',
            'relationship_started_on' => '2001-01-01',
            'relationship_started_on_precision' => 'year',
            'created_by' => $owner->id,
            'updated_by' => $owner->id,
        ]);

        $mergeId = $this->actingAs($owner)
            ->postJson("/api/families/canonical-fact-merge/people/{$absorbed->id}/merge", [
                'survivor_person_id' => $survivor->id,
            ])->assertCreated()->json('data.id');

        $this->assertSame(['Railway journeys', 'Storytelling'], $survivor->knownFor()->pluck('label')->all());
        $this->assertDatabaseHas('person_relationships', [
            'id' => $relationship->id,
            'subject_person_id' => $survivor->id,
            'relationship_started_on' => '2001-01-01 00:00:00',
            'relationship_started_on_precision' => 'year',
        ]);
        $this->assertSame('Survivor quote', $survivor->refresh()->profile_quote);
        $this->assertSame('Absorbed quote', Person::withTrashed()->findOrFail($absorbed->id)->profile_quote);

        $this->actingAs($owner)
            ->postJson("/api/families/canonical-fact-merge/person-merges/{$mergeId}/reverse")
            ->assertOk()->assertJsonPath('data.status', 'reversed');

        $this->assertSame(['Railway journeys'], $survivor->knownFor()->pluck('label')->all());
        $this->assertSame(['railway journeys', 'Storytelling'], $absorbed->knownFor()->pluck('label')->all());
        $this->assertDatabaseHas('person_relationships', [
            'id' => $relationship->id,
            'subject_person_id' => $absorbed->id,
            'relationship_started_on_precision' => 'year',
        ]);
        $this->assertSame('Absorbed quote', $absorbed->refresh()->profile_quote);
    }

    /** @return array{FamilySpace, User} */
    private function family(string $slug): array
    {
        $family = FamilySpace::factory()->create(['slug' => $slug]);

        return [$family, $this->member($family, FamilySpaceRole::Owner)];
    }

    private function member(FamilySpace $family, FamilySpaceRole $role): User
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
