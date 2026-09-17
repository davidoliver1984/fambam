<?php

namespace App\Console\Commands;

use App\Demo\DemoFamilyGuard;
use App\Demo\DemoFamilyResetter;
use Illuminate\Console\Command;
use RuntimeException;

final class ResetDemoFamily extends Command
{
    protected $signature = 'fambam:demo-family:reset {--force : Confirm destructive removal of the demo Family Space}';

    protected $description = 'Remove only the guarded local-only Mercer Family Demo';

    public function handle(DemoFamilyGuard $guard, DemoFamilyResetter $resetter): int
    {
        try {
            $guard->assertEnabled();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->components->error('Demo reset is destructive and requires --force.');

            return self::FAILURE;
        }

        try {
            $removed = $resetter->reset();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($removed ? 'Mercer Family Demo removed.' : 'Mercer Family Demo is not present.');

        return self::SUCCESS;
    }
}
