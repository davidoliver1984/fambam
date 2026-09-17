<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\NotificationCategory;
use App\Jobs\ProcessNotificationCandidate;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Tenancy\TenantOperationContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EventRsvpManager
{
    public function respond(FamilyEvent $event, FamilySpaceMembership $membership, User $actor, string $status, Request $request): EventAdmission
    {
        abort_unless(in_array($membership->role, [FamilySpaceRole::Guest, FamilySpaceRole::Contributor], true), 403);

        return DB::transaction(function () use ($event, $membership, $actor, $status, $request): EventAdmission {
            $admission = EventAdmission::query()->where('event_id', $event->id)
                ->where('family_space_membership_id', $membership->id)->lockForUpdate()->firstOrFail();
            abort_if($admission->revoked_at !== null
                || $admission->admitted_at->lessThanOrEqualTo(now()->subDays((int) config('events.admission_lifetime_days'))), 403);

            $previous = $admission->rsvp_status;
            if ($previous !== $status) {
                $admission->update(['rsvp_status' => $status, 'rsvp_responded_at' => now()]);
                if ($previous === 'pending' && $status !== 'pending') {
                    $context = TenantOperationContext::fromRequest($event->familySpace, $actor, $request);
                    $sourceActionId = (string) Str::ulid();
                    DB::afterCommit(fn () => ProcessNotificationCandidate::dispatch(
                        $context->toArray(), NotificationCategory::Attendance, $sourceActionId,
                        ['event_id' => $event->id],
                    ));
                }
            }

            return $admission;
        });
    }
}
