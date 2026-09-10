<?php

namespace App\Backups;

use Carbon\CarbonImmutable;

interface DeletionLedger
{
    public function record(string $familySpaceId, int $actorUserId, CarbonImmutable $completedAt): void;

    /** @return list<array{family_space_id: string, actor_user_id: int, completed_at: CarbonImmutable}> */
    public function completedAfter(CarbonImmutable $snapshotAt): array;
}
