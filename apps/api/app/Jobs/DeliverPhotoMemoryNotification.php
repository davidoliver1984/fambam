<?php

namespace App\Jobs;

use App\Enums\MembershipState;
use App\Enums\NotificationCategory;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Queries\DateMemoryQuery;
use App\Services\NotificationManager;
use App\Tenancy\DatabaseTenantContext;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantOperationContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeliverPhotoMemoryNotification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @param array{family_space_id:string,actor_user_id:int,correlation_id:string,traceparent:string} $context */
    public function __construct(public array $context, public string $localDate) {}

    public function uniqueId(): string
    {
        return "photo-memory:{$this->context['family_space_id']}:{$this->context['actor_user_id']}:{$this->localDate}";
    }

    public function handle(
        DatabaseTenantContext $databaseContext,
        TenantContext $tenantContext,
        DateMemoryQuery $memories,
        NotificationManager $notifications,
    ): void {
        $operation = TenantOperationContext::fromArray($this->context);
        $memory = DB::transaction(function () use ($databaseContext, $tenantContext, $memories, $operation): ?array {
            $databaseContext->establishUser($operation->actorUserId);
            $databaseContext->establishFamilySpace($operation->familySpaceId);
            $user = User::query()->whereNull('revoked_at')->find($operation->actorUserId);
            $family = FamilySpace::query()->find($operation->familySpaceId);
            $membership = FamilySpaceMembership::query()
                ->where('family_space_id', $operation->familySpaceId)
                ->where('user_id', $operation->actorUserId)
                ->where('state', MembershipState::Active->value)
                ->first();
            if ($user === null || $family === null || $membership === null) {
                return null;
            }
            if (CarbonImmutable::now($user->timezone)->toDateString() !== $this->localDate) {
                return null;
            }

            $tenantContext->establish($family, $membership, $user);
            try {
                $today = CarbonImmutable::parse($this->localDate, $user->timezone)->startOfDay();

                return $memories->onThisDay($user, $today);
            } finally {
                $tenantContext->clear();
            }
        });
        if ($memory === null) {
            return;
        }

        $notifications->processForRecipient(
            $this->context,
            $operation->actorUserId,
            NotificationCategory::PhotoMemory,
            substr(hash('sha256', 'photo-memory:'.$this->localDate), 0, 26),
            ['photo_id' => $memory['photo_id']],
        );
    }
}
