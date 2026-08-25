<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreEventAdmissionRequest;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Services\EventAdmissionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EventAdmissionController extends Controller
{
    public function __construct(private readonly EventAdmissionManager $manager) {}

    public function index(FamilySpace $familySpace, string $event): JsonResponse
    {
        $target = $this->event($familySpace, $event);
        Gate::authorize('manageAdmissions', $target);

        return response()->json(['data' => EventAdmission::query()
            ->with('membership.user:id,name,email')->where('event_id', $target->id)
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

    private function event(FamilySpace $family, string $id): FamilyEvent
    {
        return FamilyEvent::query()->where('family_space_id', $family->id)->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function payload(EventAdmission $admission): array
    {
        $admission->loadMissing('membership.user:id,name,email');

        return ['id' => $admission->id, 'membership_id' => $admission->family_space_membership_id,
            'user' => ['id' => $admission->membership->user->id, 'name' => $admission->membership->user->name,
                'email' => $admission->membership->user->email],
            'role' => $admission->membership->role->value, 'admitted_at' => $admission->admitted_at->toAtomString(),
            'revoked_at' => $admission->revoked_at?->toAtomString(),
            'valid_until' => $admission->admitted_at->addDays((int) config('events.admission_lifetime_days'))->toAtomString()];
    }
}
