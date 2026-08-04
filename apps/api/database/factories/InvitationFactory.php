<?php

namespace Database\Factories;

use App\Enums\FamilySpaceRole;
use App\Enums\InvitationStatus;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Invitation> */
class InvitationFactory extends Factory
{
    protected $model = Invitation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'family_space_id' => FamilySpace::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => FamilySpaceRole::Member,
            'token_hash' => hash('sha256', fake()->uuid()),
            'invited_by' => User::factory(),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
        ];
    }

    public function forOwner(User $owner): static
    {
        $familySpace = FamilySpace::factory()->create();
        FamilySpaceMembership::factory()->create([
            'family_space_id' => $familySpace->id,
            'user_id' => $owner->id,
            'role' => FamilySpaceRole::Owner,
        ]);

        return $this->state(fn (): array => [
            'family_space_id' => $familySpace->id,
            'invited_by' => $owner->id,
        ]);
    }
}
