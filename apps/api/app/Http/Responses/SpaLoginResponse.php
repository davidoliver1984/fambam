<?php

namespace App\Http\Responses;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Laravel\Fortify\Contracts\LoginResponse;

class SpaLoginResponse implements LoginResponse
{
    public function toResponse($request): JsonResponse
    {
        $user = $request->user();
        if ($user instanceof User) {
            $user->forceFill([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
                'last_login_user_agent' => $request->userAgent(),
            ])->save();
        }

        return response()->json(['two_factor' => false]);
    }
}
