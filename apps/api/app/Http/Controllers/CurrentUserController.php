<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->payload($user)]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->update($request->validated());

        return response()->json(['data' => $this->payload($user->refresh())]);
    }

    /** @return array{id: int, name: string, email: string, timezone: string, email_verified_at: ?string, can_invite: bool, two_factor_enabled: bool} */
    private function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'email_verified_at' => $user->email_verified_at?->toAtomString(),
            'can_invite' => $user->can_invite,
            'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
        ];
    }
}
