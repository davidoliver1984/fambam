<?php

namespace App\Tenancy;

use App\Models\FamilySpace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

class DatabaseTenantContext
{
    public function establishUser(User|int $user): void
    {
        $userId = $user instanceof User ? $user->id : $user;

        $this->setLocal('app.current_user_id', (string) $userId);
    }

    public function establishFamilySpace(
        FamilySpace|string $familySpace,
        bool $canManageMemberships = false,
        ?string $authoritativeOperation = null,
    ): void {
        $familySpaceId = $familySpace instanceof FamilySpace ? $familySpace->id : $familySpace;

        $this->setLocal('app.current_family_space_id', $familySpaceId);
        $this->setLocal('app.can_manage_memberships', $canManageMemberships ? 'true' : 'false');
        $this->setLocal('app.authoritative_tenant_operation', $authoritativeOperation ?? '');
    }

    public function clear(): void
    {
        if (DB::getDriverName() !== 'pgsql' || DB::transactionLevel() === 0) {
            return;
        }

        foreach ([
            'app.current_user_id',
            'app.current_family_space_id',
            'app.can_manage_memberships',
            'app.authoritative_tenant_operation',
        ] as $setting) {
            DB::select("SELECT set_config(?, '', true)", [$setting]);
        }
    }

    private function setLocal(string $setting, string $value): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        if (DB::transactionLevel() === 0) {
            throw new LogicException('PostgreSQL tenant context must be established inside a transaction.');
        }

        DB::select('SELECT set_config(?, ?, true)', [$setting, $value]);
    }
}
