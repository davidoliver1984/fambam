<?php

namespace App\Console\Commands;

use App\Services\RestoreDeletionReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class ReconcileRestoreDeletions extends Command
{
    protected $signature = 'fambam:restore:reconcile-deletions {--snapshot-at= : ISO-8601 backup snapshot timestamp}';

    protected $description = 'Reapply durable Family Space deletions to an isolated restored database';

    public function handle(RestoreDeletionReconciler $reconciler): int
    {
        $database = config('database.connections.pgsql.database');
        if (! app()->environment(['local', 'testing'])
            || config('backup.restore_drill_enabled') !== true
            || ! is_string($database)
            || ! str_starts_with($database, 'fambam_restore_')) {
            $this->components->error('Deletion reconciliation is restricted to an explicitly enabled isolated restore database.');

            return self::FAILURE;
        }
        $snapshot = $this->option('snapshot-at');
        if (! is_string($snapshot) || trim($snapshot) === '') {
            $this->components->error('A backup snapshot timestamp is required.');

            return self::FAILURE;
        }
        try {
            $snapshotAt = CarbonImmutable::parse($snapshot);
        } catch (\Throwable) {
            $this->components->error('The backup snapshot timestamp is invalid.');

            return self::FAILURE;
        }
        $count = $reconciler->reconcile($snapshotAt);
        $this->components->info("Reconciled {$count} post-snapshot deletion(s).");

        return self::SUCCESS;
    }
}
