<?php

namespace Tests\Fakes;

use App\Backups\DeletionLedger;
use Carbon\CarbonImmutable;

class InMemoryDeletionLedger implements DeletionLedger
{
    /** @var list<array{family_space_id: string, actor_user_id: int, completed_at: CarbonImmutable}> */
    public array $entries = [];

    public function record(string $familySpaceId, int $actorUserId, CarbonImmutable $completedAt): void
    {
        foreach ($this->entries as $entry) {
            if ($entry['family_space_id'] === $familySpaceId) {
                return;
            }
        }
        $this->entries[] = [
            'family_space_id' => $familySpaceId,
            'actor_user_id' => $actorUserId,
            'completed_at' => $completedAt,
        ];
    }

    public function completedAfter(CarbonImmutable $snapshotAt): array
    {
        return array_values(array_filter(
            $this->entries,
            fn (array $entry): bool => $entry['completed_at']->isAfter($snapshotAt),
        ));
    }
}
