<?php

namespace Database\Seeders;

use App\Demo\DemoFamilyBuilder;
use App\Demo\DemoFamilyGuard;
use Illuminate\Database\Seeder;

final class DemoFamilySeeder extends Seeder
{
    public function run(DemoFamilyGuard $guard, DemoFamilyBuilder $builder): void
    {
        $guard->assertEnabled();
        $builder->seed();
    }
}
