<?php

namespace App\Console\Commands;

use App\Enums\FamilySpaceStatus;
use App\Enums\MembershipState;
use App\Jobs\DeliverPhotoMemoryNotification;
use App\Models\FamilySpaceMembership;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DispatchPhotoMemoryNotifications extends Command
{
    private const int DELIVERY_HOUR = 9;

    protected $signature = 'fambam:dispatch-photo-memory-notifications';

    protected $description = 'Dispatch one canonical Photo Memory evaluation per due user and local day';

    public function handle(): int
    {
        $targets = DB::getDriverName() === 'pgsql'
            ? DB::select('SELECT * FROM app_photo_memory_notification_targets()')
            : FamilySpaceMembership::query()
                ->join('users', 'users.id', '=', 'family_space_memberships.user_id')
                ->join('family_spaces', 'family_spaces.id', '=', 'family_space_memberships.family_space_id')
                ->where('family_space_memberships.state', MembershipState::Active->value)
                ->where('family_spaces.status', FamilySpaceStatus::Active->value)
                ->whereNull('users.revoked_at')
                ->get([
                    'family_space_memberships.family_space_id',
                    'family_space_memberships.user_id as actor_user_id',
                    'users.timezone',
                ]);

        $dispatched = 0;
        foreach ($targets as $target) {
            try {
                $localNow = CarbonImmutable::now((string) $target->timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($localNow->hour !== self::DELIVERY_HOUR) {
                continue;
            }

            $familySpaceId = trim((string) $target->family_space_id);
            $userId = (int) $target->actor_user_id;
            DeliverPhotoMemoryNotification::dispatch(
                TenantOperationContext::forBackground($familySpaceId, $userId)->toArray(),
                $localNow->toDateString(),
            );
            $dispatched++;
        }

        $this->components->info("Dispatched {$dispatched} Photo Memory evaluation(s).");

        return self::SUCCESS;
    }
}
