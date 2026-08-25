<?php

namespace App\Services;

use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class EventAdmissionManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function admit(FamilyEvent $event, FamilySpaceMembership $membership, User $actor, Request $request): EventAdmission
    {
        return DB::transaction(function () use ($event, $membership, $actor, $request): EventAdmission {
            FamilyEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            $existing = EventAdmission::query()->where('event_id', $event->id)
                ->where('family_space_membership_id', $membership->id)->lockForUpdate()->first();
            $action = $existing === null ? 'event.admitted' : 'event.re_admitted';
            $admission = EventAdmission::query()->updateOrCreate(
                ['event_id' => $event->id, 'family_space_membership_id' => $membership->id],
                ['family_space_id' => $event->family_space_id, 'admitted_at' => now(),
                    'revoked_at' => null, 'revoked_by' => null],
            );
            $this->audit->record($action, $admission, $actor, $request);

            return $admission->load('membership.user:id,name,email');
        });
    }

    public function revoke(FamilyEvent $event, FamilySpaceMembership $membership, User $actor, Request $request): EventAdmission
    {
        return DB::transaction(function () use ($event, $membership, $actor, $request): EventAdmission {
            $admission = EventAdmission::query()->where('event_id', $event->id)
                ->where('family_space_membership_id', $membership->id)->lockForUpdate()->firstOrFail();
            if ($admission->revoked_at === null) {
                $admission->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);
                $this->audit->record('event.admission_revoked', $admission, $actor, $request);
            }

            return $admission->load('membership.user:id,name,email');
        });
    }
}
