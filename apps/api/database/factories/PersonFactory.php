<?php

namespace Database\Factories;

use App\Enums\DatePrecision;
use App\Enums\PersonIdentityStatus;
use App\Models\FamilySpace;
use App\Models\Person;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Person> */
class PersonFactory extends Factory
{
    protected $model = Person::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'family_space_id' => FamilySpace::factory(),
            'preferred_name' => fake()->name(),
            'alternate_names' => [],
            'identity_status' => PersonIdentityStatus::Confirmed,
            'birth_date' => null,
            'birth_date_precision' => DatePrecision::Unknown,
            'is_deceased' => false,
            'death_date' => null,
            'death_date_precision' => DatePrecision::Unknown,
            'biography' => null,
            'confirmed_at' => now(),
        ];
    }

    public function provisional(): static
    {
        return $this->state(fn (): array => [
            'identity_status' => PersonIdentityStatus::Provisional,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);
    }
}
