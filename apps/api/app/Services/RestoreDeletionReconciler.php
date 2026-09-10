<?php

namespace App\Services;

use App\Backups\DeletionLedger;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;

class RestoreDeletionReconciler
{
    public function __construct(
        private readonly DeletionLedger $ledger,
        private readonly FamilySpaceDeletionManager $deletions,
    ) {}

    public function reconcile(CarbonImmutable $snapshotAt): int
    {
        $count = 0;
        foreach ($this->ledger->completedAfter($snapshotAt) as $entry) {
            $this->deletions->reapplyAfterRestore(TenantOperationContext::forBackground(
                $entry['family_space_id'],
                $entry['actor_user_id'],
            ));
            $count++;
        }

        return $count;
    }
}
