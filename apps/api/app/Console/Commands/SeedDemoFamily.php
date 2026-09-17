<?php

namespace App\Console\Commands;

use App\Demo\DemoFamilyBuilder;
use Illuminate\Console\Command;
use RuntimeException;

final class SeedDemoFamily extends Command
{
    protected $signature = 'fambam:demo-family:seed';

    protected $description = 'Create the guarded local-only Mercer Family Demo';

    public function handle(DemoFamilyBuilder $builder): int
    {
        try {
            $summary = $builder->seed();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info($summary['created'] ? 'Mercer Family Demo created.' : 'Mercer Family Demo already exists; no duplicates were added.');
        $this->table(['Entity', 'Count'], collect($summary)->filter(fn ($value, $key) => is_int($value) && $key !== 'created')->map(fn ($value, $key) => [$key, $value])->values()->all());

        return self::SUCCESS;
    }
}
