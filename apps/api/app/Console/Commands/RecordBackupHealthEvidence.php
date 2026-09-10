<?php

namespace App\Console\Commands;

use App\Services\BackupHealthService;
use Illuminate\Console\Command;
use InvalidArgumentException;

class RecordBackupHealthEvidence extends Command
{
    protected $signature = 'fambam:backup-health:record
        {--database-reference= : Inspectable database backup artifact reference}
        {--database-sha256= : SHA-256 of the database backup artifact}
        {--object-storage-reference= : Inspectable object-storage version evidence reference}
        {--restore-result= : Restore drill result: passed or failed}
        {--restore-reference= : Inspectable restore-drill evidence reference}';

    protected $description = 'Record evidence produced by a real backup or restore operation';

    public function handle(BackupHealthService $health): int
    {
        $databaseReference = $this->stringOption('database-reference');
        $databaseSha256 = $this->stringOption('database-sha256');
        $objectStorageReference = $this->stringOption('object-storage-reference');
        $restoreResult = $this->stringOption('restore-result');
        $restoreReference = $this->stringOption('restore-reference');

        $hasBackup = $databaseReference !== '' || $databaseSha256 !== '' || $objectStorageReference !== '';
        $hasRestore = $restoreResult !== '' || $restoreReference !== '';
        if (! $hasBackup && ! $hasRestore) {
            $this->components->error('Provide complete backup evidence or complete restore-drill evidence.');

            return self::FAILURE;
        }

        try {
            if ($hasBackup) {
                if ($databaseReference === '' || $databaseSha256 === '' || $objectStorageReference === '') {
                    throw new InvalidArgumentException('Database reference, database SHA-256 and object-storage reference are required together.');
                }
                $health->recordBackupArtifacts($databaseReference, $databaseSha256, $objectStorageReference);
            }
            if ($hasRestore) {
                if ($restoreResult === '' || $restoreReference === '') {
                    throw new InvalidArgumentException('Restore result and restore reference are required together.');
                }
                $health->recordRestoreDrill($restoreResult, $restoreReference);
            }
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Backup health evidence recorded.');

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
