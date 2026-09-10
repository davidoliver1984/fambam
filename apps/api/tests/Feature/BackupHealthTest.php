<?php

namespace Tests\Feature;

use App\Models\BackupHealthEvidence;
use App\Services\BackupHealthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackupHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_missing_or_stale_evidence_degrades_health(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        config([
            'backup.database_max_age_hours' => 1,
            'backup.object_storage_max_age_hours' => 1,
            'backup.restore_drill_max_age_days' => 1,
        ]);
        $health = app(BackupHealthService::class);

        $missing = $health->status();
        $this->assertSame('degraded', $missing['health']);
        $this->assertSame('missing', $missing['database']['state']);
        $this->assertSame('missing', $missing['object_storage']['state']);
        $this->assertSame('missing', $missing['restore_drill']['state']);

        BackupHealthEvidence::query()->create([
            'id' => 1,
            'last_database_backup_at' => now()->subHours(2),
            'database_backup_reference' => '.local/backups/database/stale.dump',
            'database_backup_sha256' => str_repeat('a', 64),
            'last_object_storage_verification_at' => now(),
            'object_storage_reference' => '.local/backups/object-storage/current.json',
            'last_restore_drill_at' => now(),
            'last_restore_drill_result' => 'passed',
            'restore_drill_reference' => '.local/backups/restore/current.json',
        ]);

        $stale = $health->status();
        $this->assertSame('degraded', $stale['health']);
        $this->assertSame('stale', $stale['database']['state']);
        $this->assertSame('healthy', $stale['object_storage']['state']);
        $this->assertSame('healthy', $stale['restore_drill']['state']);

        BackupHealthEvidence::query()->whereKey(1)->update([
            'last_database_backup_at' => now(),
            'last_object_storage_verification_at' => now()->subHours(2),
        ]);
        $this->assertSame('stale', $health->status()['object_storage']['state']);
        $this->assertSame('degraded', $health->status()['health']);

        BackupHealthEvidence::query()->whereKey(1)->update([
            'last_object_storage_verification_at' => now(),
            'last_restore_drill_at' => now()->subDays(2),
        ]);
        $this->assertSame('stale', $health->status()['restore_drill']['state']);
        $this->assertSame('degraded', $health->status()['health']);
    }

    public function test_real_backup_and_successful_restore_evidence_produce_healthy_status(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        $health = app(BackupHealthService::class);
        $hash = hash('sha256', 'database backup');

        $health->recordBackupArtifacts(
            '.local/backups/database/current.dump',
            $hash,
            '.local/backups/object-storage/current.json',
        );
        $this->assertSame('degraded', $health->status()['health']);
        $this->assertSame('missing', $health->status()['restore_drill']['state']);

        $health->recordRestoreDrill('passed', '.local/backups/restore/current.json');
        $status = $health->status();
        $this->assertSame('healthy', $status['health']);
        $this->assertSame($hash, $status['database']['sha256']);
        $this->assertSame('healthy', $status['database']['state']);
        $this->assertSame('healthy', $status['object_storage']['state']);
        $this->assertSame('healthy', $status['restore_drill']['state']);

        $this->artisan('fambam:backup-health')
            ->expectsOutputToContain('"health": "healthy"')
            ->assertSuccessful();
    }

    public function test_failed_restore_and_invalid_evidence_are_reported_safely(): void
    {
        CarbonImmutable::setTestNow('2026-09-10 12:00:00');
        $health = app(BackupHealthService::class);
        $health->recordBackupArtifacts('database.dump', str_repeat('b', 64), 'object-storage.json');
        $health->recordRestoreDrill('failed', 'restore-failure.json');

        $this->assertSame('failed', $health->status()['restore_drill']['state']);
        $this->assertSame('degraded', $health->status()['health']);
        $this->artisan('fambam:backup-health')->assertFailed();

        $this->artisan('fambam:backup-health:record', [
            '--database-reference' => 'database.dump',
            '--database-sha256' => 'not-a-sha256',
            '--object-storage-reference' => 'object-storage.json',
        ])->expectsOutputToContain('SHA-256')->assertFailed();
    }
}
