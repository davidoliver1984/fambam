<?php

namespace App\Services;

use App\Models\BackupHealthEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class BackupHealthService
{
    /**
     * @return array{
     *   health: 'healthy'|'degraded',
     *   database: array{last_successful_at: ?string, reference: ?string, sha256: ?string, age_seconds: ?int, max_age_seconds: int, state: 'healthy'|'missing'|'stale'},
     *   object_storage: array{last_verified_at: ?string, reference: ?string, age_seconds: ?int, max_age_seconds: int, state: 'healthy'|'missing'|'stale'},
     *   restore_drill: array{last_run_at: ?string, result: ?string, reference: ?string, age_seconds: ?int, max_age_seconds: int, state: 'healthy'|'missing'|'stale'|'failed'}
     * }
     */
    public function status(): array
    {
        $evidence = BackupHealthEvidence::query()->find(1);
        $database = $this->timedSignal(
            $evidence?->last_database_backup_at,
            $this->positiveThreshold('backup.database_max_age_hours', 3600),
        );
        $objectStorage = $this->timedSignal(
            $evidence?->last_object_storage_verification_at,
            $this->positiveThreshold('backup.object_storage_max_age_hours', 3600),
        );
        $restore = $this->timedSignal(
            $evidence?->last_restore_drill_at,
            $this->positiveThreshold('backup.restore_drill_max_age_days', 86400),
        );
        $restoreState = $restore['state'];
        if ($restoreState === 'healthy' && $evidence?->last_restore_drill_result !== 'passed') {
            $restoreState = 'failed';
        }

        $healthy = $database['state'] === 'healthy'
            && $objectStorage['state'] === 'healthy'
            && $restoreState === 'healthy';

        return [
            'health' => $healthy ? 'healthy' : 'degraded',
            'database' => [
                'last_successful_at' => $evidence?->last_database_backup_at?->toIso8601String(),
                'reference' => $evidence?->database_backup_reference,
                'sha256' => $evidence?->database_backup_sha256,
                'age_seconds' => $database['age_seconds'],
                'max_age_seconds' => $database['max_age_seconds'],
                'state' => $database['state'],
            ],
            'object_storage' => [
                'last_verified_at' => $evidence?->last_object_storage_verification_at?->toIso8601String(),
                'reference' => $evidence?->object_storage_reference,
                'age_seconds' => $objectStorage['age_seconds'],
                'max_age_seconds' => $objectStorage['max_age_seconds'],
                'state' => $objectStorage['state'],
            ],
            'restore_drill' => [
                'last_run_at' => $evidence?->last_restore_drill_at?->toIso8601String(),
                'result' => $evidence?->last_restore_drill_result,
                'reference' => $evidence?->restore_drill_reference,
                'age_seconds' => $restore['age_seconds'],
                'max_age_seconds' => $restore['max_age_seconds'],
                'state' => $restoreState,
            ],
        ];
    }

    public function recordBackupArtifacts(
        string $databaseReference,
        string $databaseSha256,
        string $objectStorageReference,
    ): void {
        $this->assertReference($databaseReference);
        $this->assertReference($objectStorageReference);
        if (! preg_match('/^[a-f0-9]{64}$/', $databaseSha256)) {
            throw new InvalidArgumentException('The database backup SHA-256 must be 64 lowercase hexadecimal characters.');
        }

        DB::transaction(function () use ($databaseReference, $databaseSha256, $objectStorageReference): void {
            $evidence = BackupHealthEvidence::query()->firstOrNew(['id' => 1]);
            $recordedAt = now();
            $evidence->forceFill([
                'last_database_backup_at' => $recordedAt,
                'database_backup_reference' => $databaseReference,
                'database_backup_sha256' => $databaseSha256,
                'last_object_storage_verification_at' => $recordedAt,
                'object_storage_reference' => $objectStorageReference,
            ])->save();
        });
    }

    public function recordRestoreDrill(string $result, string $reference): void
    {
        if (! in_array($result, ['passed', 'failed'], true)) {
            throw new InvalidArgumentException('The restore-drill result must be passed or failed.');
        }
        $this->assertReference($reference);
        BackupHealthEvidence::query()->updateOrCreate(
            ['id' => 1],
            [
                'last_restore_drill_at' => now(),
                'last_restore_drill_result' => $result,
                'restore_drill_reference' => $reference,
            ],
        );
    }

    /** @return array{age_seconds: ?int, max_age_seconds: int, state: 'healthy'|'missing'|'stale'} */
    private function timedSignal(?CarbonImmutable $recordedAt, int $maxAgeSeconds): array
    {
        if ($recordedAt === null) {
            return ['age_seconds' => null, 'max_age_seconds' => $maxAgeSeconds, 'state' => 'missing'];
        }

        $ageSeconds = max(0, (int) $recordedAt->diffInSeconds(CarbonImmutable::now(), false));

        return [
            'age_seconds' => $ageSeconds,
            'max_age_seconds' => $maxAgeSeconds,
            'state' => $ageSeconds > $maxAgeSeconds ? 'stale' : 'healthy',
        ];
    }

    private function positiveThreshold(string $key, int $multiplier): int
    {
        $value = (int) config($key);
        if ($value < 1) {
            throw new InvalidArgumentException("Backup health threshold {$key} must be positive.");
        }

        return $value * $multiplier;
    }

    private function assertReference(string $reference): void
    {
        if ($reference === '' || strlen($reference) > 500 || preg_match('/[\x00-\x1F\x7F]/', $reference)) {
            throw new InvalidArgumentException('Backup evidence references must be non-empty, bounded and contain no control characters.');
        }
    }
}
