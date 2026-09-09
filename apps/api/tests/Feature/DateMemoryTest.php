<?php

namespace Tests\Feature;

use App\Enums\DatePrecision;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DateMemoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_date_memories_use_historical_precision_with_honest_reasons(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 12:00:00');
        $family = FamilySpace::factory()->create(['slug' => 'date-memories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);

        $this->photo($family, $owner, DatePrecision::Exact, '1984-09-09', 'Exact memory');
        $this->photo($family, $owner, DatePrecision::Month, '1990-09-01', 'Month memory');
        $this->photo($family, $owner, DatePrecision::Approximate, '2000-09-09', 'Approximate memory');
        $this->photo($family, $owner, DatePrecision::Year, '1975-01-01', 'Year memory');
        $this->photo($family, $owner, DatePrecision::Decade, '1980-01-01', 'Decade memory');
        $this->photo($family, $owner, DatePrecision::Exact, '1984-08-09', 'Wrong day');
        $this->photo($family, $owner, DatePrecision::Unknown, null, 'Unknown date');
        $this->photo($family, $owner, null, null, 'Created today', createdAt: now());

        $response = $this->actingAs($viewer)
            ->getJson('/api/families/date-memories/memories/date-based')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $response
            ->assertJsonPath('data.0.reason', 'On this day in 1984')
            ->assertJsonPath('data.1.reason', 'Sometime in September 1990')
            ->assertJsonPath('data.2.reason', 'Around 2000')
            ->assertJsonPath('data.3.reason', 'In 1975')
            ->assertJsonPath('data.4.reason', 'From the 1980s')
            ->assertJsonMissing(['label' => 'Created today']);
    }

    public function test_date_memories_apply_current_photo_authorization_and_resurfacing_exclusion(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 12:00:00');
        $family = FamilySpace::factory()->create(['slug' => 'private-date-memories']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $viewer = $this->member($family, FamilySpaceRole::Member);
        $visible = $this->photo($family, $owner, DatePrecision::Exact, '1984-09-09', 'Visible memory');
        $suppressed = $this->photo(
            $family,
            $owner,
            DatePrecision::Exact,
            '1985-09-09',
            'Suppressed memory',
            doNotResurface: true,
        );
        $this->photo(
            $family,
            $owner,
            DatePrecision::Exact,
            '1986-09-09',
            'Private memory',
            visibility: PhotoVisibility::Private,
        );

        $this->actingAs($viewer)
            ->getJson('/api/families/private-date-memories/memories/date-based')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.photo_id', $visible->id);

        $this->actingAs($viewer)
            ->getJson("/api/families/private-date-memories/photos/{$suppressed->id}")
            ->assertOk()
            ->assertJsonPath('data.do_not_resurface', true);
        $this->actingAs($viewer)
            ->getJson('/api/families/private-date-memories/photos?historical_year=1985')
            ->assertOk()
            ->assertJsonPath('data.0.id', $suppressed->id);
    }

    public function test_resurfacing_exclusion_uses_existing_photo_update_authority(): void
    {
        $family = FamilySpace::factory()->create(['slug' => 'resurfacing-authority']);
        $owner = $this->member($family, FamilySpaceRole::Owner);
        $creator = $this->member($family, FamilySpaceRole::Member);
        $otherMember = $this->member($family, FamilySpaceRole::Member);
        $photo = $this->photo($family, $creator, DatePrecision::Exact, '1984-09-09', 'Managed memory');

        $this->actingAs($otherMember)
            ->patchJson("/api/families/resurfacing-authority/photos/{$photo->id}", [
                'do_not_resurface' => true,
            ])->assertForbidden();

        $this->actingAs($creator)
            ->patchJson("/api/families/resurfacing-authority/photos/{$photo->id}", [
                'do_not_resurface' => true,
            ])->assertOk()
            ->assertJsonPath('data.do_not_resurface', true);

        $this->actingAs($owner)
            ->patchJson("/api/families/resurfacing-authority/photos/{$photo->id}", [
                'do_not_resurface' => false,
            ])->assertOk()
            ->assertJsonPath('data.do_not_resurface', false);
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

    private function photo(
        FamilySpace $family,
        User $creator,
        ?DatePrecision $precision,
        ?string $date,
        string $caption,
        bool $doNotResurface = false,
        PhotoVisibility $visibility = PhotoVisibility::FamilySpace,
        mixed $createdAt = null,
    ): Photo {
        return Photo::factory()->create([
            'family_space_id' => $family->id,
            'created_by' => $creator->id,
            'visibility' => $visibility,
            'caption' => $caption,
            'historical_date_precision' => $precision,
            'historical_date' => $date,
            'do_not_resurface' => $doNotResurface,
            ...($createdAt === null ? [] : ['created_at' => $createdAt]),
        ]);
    }
}
