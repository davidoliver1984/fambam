<?php

namespace Tests\Feature;

use App\Backups\DeletionLedger;
use App\Enums\FamilySpaceStatus;
use App\Media\FamilyMediaStorageCleaner;
use App\Services\RestoreDeletionReconciler;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class RestoreReconciliationPostgresTest extends TestCase
{
    private ConnectionInterface $admin;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'pgsql' || config('database.connections.pgsql_admin.username') === null) {
            $this->markTestSkipped('Restore reconciliation requires runtime and administrative PostgreSQL connections.');
        }
        $this->admin = DB::connection('pgsql_admin');
        $this->admin->unprepared('TRUNCATE TABLE users RESTART IDENTITY CASCADE');
    }

    protected function tearDown(): void
    {
        if (isset($this->admin)) {
            DB::purge('pgsql_admin');
        }
        parent::tearDown();
    }

    public function test_post_snapshot_deletion_is_reapplied_through_forced_rls(): void
    {
        $actorId = (int) $this->admin->table('users')->insertGetId([
            'name' => 'Restore Actor',
            'email' => 'restore-actor@example.test',
            'password' => 'not-used',
            'timezone' => 'Europe/London',
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $familySpaceId = (string) Str::ulid();
        $this->admin->transaction(function () use ($familySpaceId, $actorId): void {
            $this->admin->table('family_spaces')->insert([
                'id' => $familySpaceId,
                'slug' => 'restored-postgres-family',
                'name' => 'Restored PostgreSQL Family',
                'status' => FamilySpaceStatus::Active->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->admin->table('family_space_memberships')->insert([
                'id' => (string) Str::ulid(),
                'family_space_id' => $familySpaceId,
                'user_id' => $actorId,
                'role' => 'owner',
                'state' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $snapshotAt = CarbonImmutable::parse('2026-09-10T12:00:00Z');
        $ledger = new RestorePostgresDeletionLedger([[
            'family_space_id' => $familySpaceId,
            'actor_user_id' => $actorId,
            'completed_at' => $snapshotAt->addMinute(),
        ]]);
        $cleaner = new RestorePostgresMediaCleaner;
        $this->app->instance(DeletionLedger::class, $ledger);
        $this->app->instance(FamilyMediaStorageCleaner::class, $cleaner);

        $this->assertSame(1, app(RestoreDeletionReconciler::class)->reconcile($snapshotAt));

        $this->assertSame(
            FamilySpaceStatus::Deleted->value,
            $this->admin->table('family_spaces')->where('id', $familySpaceId)->value('status'),
        );
        $this->assertSame('removed', $this->admin->table('family_space_memberships')
            ->where('family_space_id', $familySpaceId)->value('state'));
        $this->assertSame([$familySpaceId], $cleaner->familySpaceIds);
        $this->assertSame(1, $this->admin->table('audit_events')
            ->where('family_space_id', $familySpaceId)
            ->where('action', 'family_space.deleted')
            ->count());
    }
}

class RestorePostgresDeletionLedger implements DeletionLedger
{
    /** @param list<array{family_space_id: string, actor_user_id: int, completed_at: CarbonImmutable}> $entries */
    public function __construct(private readonly array $entries) {}

    public function record(string $familySpaceId, int $actorUserId, CarbonImmutable $completedAt): void
    {
        // The source ledger entry remains the durable record during replay.
    }

    public function completedAfter(CarbonImmutable $snapshotAt): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (array $entry): bool => $entry['completed_at']->isAfter($snapshotAt),
        ));
    }
}

class RestorePostgresMediaCleaner implements FamilyMediaStorageCleaner
{
    /** @var list<string> */
    public array $familySpaceIds = [];

    public function deleteFamilyMedia(string $familySpaceId): void
    {
        $this->familySpaceIds[] = $familySpaceId;
    }
}
