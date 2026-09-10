<?php

namespace Tests\Feature;

use App\Models\BackupHealthEvidence;
use App\Services\BackupHealthService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackupHealthPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Backup-health constraints require runtime and administrative PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->table('backup_health_evidence')->delete();
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_runtime_role_records_evidence_and_postgres_enforces_singleton_and_result(): void
    {
        app(BackupHealthService::class)->recordBackupArtifacts(
            '.local/backups/database/postgres.dump',
            str_repeat('c', 64),
            '.local/backups/object-storage/postgres.json',
        );
        $this->assertSame(1, BackupHealthEvidence::query()->count());

        try {
            $this->admin->table('backup_health_evidence')->insert([
                'id' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('PostgreSQL accepted a second backup-health identity.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('backup_health_evidence_singleton_check', $exception->getMessage());
        }

        try {
            $this->admin->table('backup_health_evidence')->where('id', 1)->update([
                'last_restore_drill_result' => 'unknown',
            ]);
            $this->fail('PostgreSQL accepted an invalid restore result.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('backup_health_evidence_restore_result_check', $exception->getMessage());
        }
    }
}
