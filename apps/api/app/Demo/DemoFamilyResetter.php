<?php

namespace App\Demo;

use App\Models\FamilySpace;
use App\Models\User;
use App\Services\FamilySpaceDeletionManager;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DemoFamilyResetter
{
    public function __construct(
        private readonly DemoFamilyGuard $guard,
        private readonly DatabaseTenantContext $databaseTenantContext,
        private readonly FamilySpaceDeletionManager $deletionManager,
    ) {}

    public function reset(): bool
    {
        $this->guard->assertEnabled();
        $owner = User::query()->where('email', DemoFamilyBuilder::OWNER_EMAIL)->first();
        if ($owner === null) {
            return false;
        }

        $family = DB::transaction(function () use ($owner): ?FamilySpace {
            $this->databaseTenantContext->establishUser($owner);

            return FamilySpace::query()->where('slug', config('demo-family.slug'))->first();
        });
        if ($family === null) {
            return false;
        }

        DB::transaction(function () use ($family, $owner): void {
            $this->databaseTenantContext->establishUser($owner);
            $this->databaseTenantContext->establishFamilySpace($family, canManageMemberships: true);
            $request = Request::create('/local-demo-family/reset', 'DELETE');
            $request->attributes->set('correlation_id', 'local-demo-family-reset');
            $this->deletionManager->request($family, $owner, $request);
            FamilySpace::query()->whereKey($family->id)->update(['scheduled_deletion_at' => now()->subSecond()]);
        });
        $this->deletionManager->teardown(TenantOperationContext::forBackground($family->id, $owner->id));

        return true;
    }
}
