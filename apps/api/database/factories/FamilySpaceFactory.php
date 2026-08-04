<?php

namespace Database\Factories;

use App\Enums\FamilySpaceStatus;
use App\Models\FamilySpace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<FamilySpace> */
class FamilySpaceFactory extends Factory
{
    protected $model = FamilySpace::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'status' => FamilySpaceStatus::Active,
        ];
    }
}
