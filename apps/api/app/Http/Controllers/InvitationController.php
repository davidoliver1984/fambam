<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\IssueInvitationRequest;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Invitation;
use App\Models\User;
use App\Queries\InvitationQuery;
use App\Services\InvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class InvitationController extends Controller
{
    public function __construct(
        private readonly InvitationManager $invitations,
        private readonly InvitationQuery $invitationQuery,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('manageInvitations', $familySpace);
        $invitations = $this->invitationQuery
            ->listForFamilySpace($familySpace)
            ->map($this->payload(...));

        return response()->json(['data' => $invitations]);
    }

    public function store(FamilySpace $familySpace, IssueInvitationRequest $request): JsonResponse
    {
        Gate::authorize('manageInvitations', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $event = $request->validated('event_id') === null ? null : FamilyEvent::query()
            ->where('family_space_id', $familySpace->id)->findOrFail($request->validated('event_id'));
        $invitation = $this->invitations->issue(
            $actor,
            $familySpace,
            $request->validated('email'),
            $event === null ? FamilySpaceRole::from($request->validated('role')) : FamilySpaceRole::Guest,
            $request,
            $event,
        );

        return response()->json(['data' => $this->payload($invitation)], 201);
    }

    public function resend(FamilySpace $familySpace, int $invitation, Request $request): JsonResponse
    {
        Gate::authorize('manageInvitations', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $this->invitationQuery->findForFamilySpace($familySpace, $invitation);
        $invitation = $this->invitations->resend($invitation, $actor, $request);

        return response()->json(['data' => $this->payload($invitation)]);
    }

    public function revoke(FamilySpace $familySpace, int $invitation, Request $request): JsonResponse
    {
        Gate::authorize('manageInvitations', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $this->invitationQuery->findForFamilySpace($familySpace, $invitation);
        $invitation = $this->invitations->revoke($invitation, $actor, $request);

        return response()->json(['data' => $this->payload($invitation)]);
    }

    /** @return array{id: int, family_space_id: string, event_id: ?string, email: string, role: string, status: string, expires_at: string, accepted_at: ?string, revoked_at: ?string, acceptable: bool} */
    private function payload(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'family_space_id' => $invitation->family_space_id,
            'event_id' => $invitation->event_id,
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'status' => $invitation->status->value,
            'expires_at' => $invitation->expires_at->toAtomString(),
            'accepted_at' => $invitation->accepted_at?->toAtomString(),
            'revoked_at' => $invitation->revoked_at?->toAtomString(),
            'acceptable' => $invitation->status->value === 'pending' && $invitation->expires_at->isFuture(),
        ];
    }
}
