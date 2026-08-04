<?php

namespace Database\Factories;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FamilySpaceMembership> */
class FamilySpaceMembershipFactory extends Factory
{
    protected $model = FamilySpaceMembership::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'family_space_id' => FamilySpace::factory(),
            'user_id' => User::factory(),
            'role' => FamilySpaceRole::Member,
            'state' => MembershipState::Active,
        ];
    }
}
