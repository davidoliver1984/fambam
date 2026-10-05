<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserAgentLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RecentSignInController extends Controller
{
    public function __invoke(Request $request, UserAgentLabel $labels): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $user->last_login_at === null ? [] : [[
            'signed_in_at' => $user->last_login_at->toAtomString(),
            'device' => $labels->parse($user->last_login_user_agent),
            'location' => null,
        ]];

        return response()->json(['data' => $data]);
    }
}
