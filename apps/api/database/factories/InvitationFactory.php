<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
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
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', fake()->uuid()),
            'invited_by' => User::factory()->canInvite(),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(7),
        ];
    }
}
