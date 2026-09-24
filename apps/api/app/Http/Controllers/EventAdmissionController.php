<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventAdmissionRequest;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Services\EventAdmissionManager;
use App\Services\EventRsvpManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventAdmissionController extends Controller
{
    public function __construct(private readonly EventAdmissionManager $manager, private readonly EventRsvpManager $rsvpManager) {}

    public function index(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        Gate::authorize('manageAdmissions', $target);

        return response()->json(['data' => EventAdmission::query()
            ->with('membership.user.personAccountLinks:id,family_space_id,user_id,person_id')->where('event_id', $target->id)
            ->orderByDesc('admitted_at')->get()->map($this->payload(...))]);
    }

    public function store(FamilySpace $familySpace, string $event, StoreEventAdmissionRequest $request): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        Gate::authorize('manageAdmissions', $target);
        $membership = FamilySpaceMembership::query()->where('family_space_id', $familySpace->id)
            ->where('state', 'active')->findOrFail($request->validated('membership_id'));
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->manager->admit($target, $membership, $actor, $request),
        )], 201);
    }

    public function destroy(FamilySpace $familySpace, string $event, string $membership, Request $request): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        Gate::authorize('manageAdmissions', $target);
        $member = FamilySpaceMembership::query()->where('family_space_id', $familySpace->id)->findOrFail($membership);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload(
            $this->manager->revoke($target, $member, $actor, $request),
        )]);
    }

    public function rsvp(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        $status = $request->validate(['status' => ['required', 'in:pending,going,not_attending']])['status'];
        /** @var User $actor */
        $actor = $request->user();
        $membership = FamilySpaceMembership::query()->where('family_space_id', $familySpace->id)
            ->where('user_id', $actor->id)->where('state', 'active')->firstOrFail();

        return response()->json(['data' => $this->payload(
            $this->rsvpManager->respond($target, $membership, $actor, $status, $request),
        )]);
    }

    public function rsvps(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        Gate::authorize('view', $target);
        $rows = EventAdmission::query()->with('membership.user:id,name,email')
            ->where('event_id', $target->id)->whereNull('revoked_at')
            ->where('admitted_at', '>', now()->subDays((int) config('events.admission_lifetime_days')))
            ->orderBy('id')->get();
        $groups = ['going' => [], 'pending' => [], 'not_attending' => []];
        foreach ($rows as $row) {
            if ($row->membership->state->value !== 'active') {
                continue;
            }
            $groups[$row->rsvp_status][] = ['id' => $row->id,
                'user' => ['id' => $row->membership->user->id, 'name' => $row->membership->user->name]];
        }

        return response()->json(['data' => $groups]);
    }

    private function event(FamilySpace $family, string $id): FamilyEvent
    {
        return FamilyEvent::query()->where('family_space_id', $family->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(EventAdmission $admission): array
    {
        $admission->loadMissing('membership.user.personAccountLinks:id,family_space_id,user_id,person_id');

        $personId = $admission->membership->user->personAccountLinks
            ->firstWhere('family_space_id', $admission->family_space_id)?->person_id;

        return ['id' => $admission->id, 'membership_id' => $admission->family_space_membership_id,
            'user' => ['id' => $admission->membership->user->id, 'name' => $admission->membership->user->name,
                'email' => $admission->membership->user->email, 'person_id' => $personId],
            'role' => $admission->membership->role->value, 'admitted_at' => $admission->admitted_at->toAtomString(),
            'revoked_at' => $admission->revoked_at?->toAtomString(),
            'rsvp_status' => $admission->rsvp_status,
            'rsvp_responded_at' => $admission->rsvp_responded_at?->toAtomString(),
            'valid_until' => $admission->admitted_at->addDays((int) config('events.admission_lifetime_days'))->toAtomString()];
    }
}
