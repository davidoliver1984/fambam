<?php

namespace App\Console\Commands;

use App\Services\BackupHealthService;
use Illuminate\Console\Command;
use JsonException;

class ShowBackupHealth extends Command
{
    protected $signature = 'fambam:backup-health';

    protected $description = 'Show provider-neutral backup and restore health as JSON';

    /** @throws JsonException */
    public function handle(BackupHealthService $health): int
    {
        $status = $health->status();
        $this->line(json_encode($status, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        return $status['health'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }
}
