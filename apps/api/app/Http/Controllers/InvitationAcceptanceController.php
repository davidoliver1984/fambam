<?php

namespace App\Http\Controllers;

use App\Http\Requests\AcceptInvitationRequest;
use App\Http\Requests\ExchangeInvitationRequest;
use App\Services\InvitationManager;
use Illuminate\Http\JsonResponse;

class InvitationAcceptanceController extends Controller
{
    public function __construct(private readonly InvitationManager $invitations) {}

    public function exchange(ExchangeInvitationRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->invitations->exchange($request->validated('token'), $request),
        ]);
    }

    public function accept(AcceptInvitationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $this->invitations->accept($validated['claim_token'], [
            'name' => $validated['name'] ?? null,
            'password' => $validated['password'] ?? null,
            'timezone' => $validated['timezone'] ?? null,
        ], $request);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'email' => $user->email,
            ],
        ], 201);
    }
}
