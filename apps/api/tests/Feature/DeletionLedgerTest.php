<?php

namespace Tests\Feature;

use App\Backups\S3DeletionLedger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeletionLedgerTest extends TestCase
{
    public function test_ledger_is_idempotent_independent_and_filters_by_snapshot_time(): void
    {
        Storage::fake('s3');
        config(['backup.deletion_ledger_prefix' => 'platform/deletion-ledger']);
        $ledger = new S3DeletionLedger;
        $before = (string) Str::ulid();
        $after = (string) Str::ulid();
        $snapshot = CarbonImmutable::parse('2026-09-10T12:00:00Z');

        $ledger->record($before, 1, $snapshot->subMinute());
        $ledger->record($after, 2, $snapshot->addMinute());
        $ledger->record($after, 2, $snapshot->addHour());

        Storage::disk('s3')->assertExists("platform/deletion-ledger/{$before}.json");
        Storage::disk('s3')->assertExists("platform/deletion-ledger/{$after}.json");
        $entries = $ledger->completedAfter($snapshot);
        $this->assertCount(1, $entries);
        $this->assertSame($after, $entries[0]['family_space_id']);
        $this->assertSame(2, $entries[0]['actor_user_id']);
        $this->assertSame($snapshot->addMinute()->toIso8601String(), $entries[0]['completed_at']->toIso8601String());
    }
}
