<?php

namespace App\Http\Controllers;

use App\Http\Requests\IssueInvitationRequest;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvitationController extends Controller
{
    public function __construct(private readonly InvitationManager $invitations) {}

    public function index(): JsonResponse
    {
        $invitations = Invitation::query()->latest()->get()->map($this->payload(...));

        return response()->json(['data' => $invitations]);
    }

    public function store(IssueInvitationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $this->invitations->issue($actor, $request->validated('email'), $request);

        return response()->json(['data' => $this->payload($invitation)], 201);
    }

    public function resend(Invitation $invitation, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $this->invitations->resend($invitation, $actor, $request);

        return response()->json(['data' => $this->payload($invitation)]);
    }

    public function revoke(Invitation $invitation, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $invitation = $this->invitations->revoke($invitation, $actor, $request);

        return response()->json(['data' => $this->payload($invitation)]);
    }

    /** @return array{id: int, email: string, status: string, expires_at: string, accepted_at: ?string, revoked_at: ?string, acceptable: bool} */
    private function payload(Invitation $invitation): array
    {
        return [
            'id' => $invitation->id,
            'email' => $invitation->email,
            'status' => $invitation->status->value,
            'expires_at' => $invitation->expires_at->toAtomString(),
            'accepted_at' => $invitation->accepted_at?->toAtomString(),
            'revoked_at' => $invitation->revoked_at?->toAtomString(),
            'acceptable' => $invitation->status->value === 'pending' && $invitation->expires_at->isFuture(),
        ];
    }
}
