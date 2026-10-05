<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateEmailRequest;
use App\Models\User;
use App\Services\EmailChangeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountEmailController extends Controller
{
    public function requestChange(UpdateEmailRequest $request, EmailChangeManager $changes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $changes->request($user, $request->validated('email'), $request);

        return response()->json(['data' => [
            'email' => $user->email,
            'pending_email' => $user->refresh()->pending_email,
            'pending_email_requested_at' => $user->pending_email_requested_at?->toAtomString(),
        ]], 202);
    }

    public function verify(Request $request, int $user, string $hash, EmailChangeManager $changes): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $updated = $changes->verify($actor, $user, $hash, $request);

        return response()->json(['data' => [
            'email' => $updated->email,
            'email_verified_at' => $updated->email_verified_at?->toAtomString(),
            'pending_email' => null,
        ]]);
    }
}
