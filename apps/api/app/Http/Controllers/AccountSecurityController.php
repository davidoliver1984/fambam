<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use App\Services\RevokeUserAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AccountSecurityController extends Controller
{
    public function updatePassword(UpdatePasswordRequest $request, RevokeUserAccess $revokeAccess): Response
    {
        /** @var User $user */
        $user = $request->user();
        DB::transaction(function () use ($user, $request, $revokeAccess): void {
            $user->forceFill([
                'password' => Hash::make($request->validated('password')),
            ])->save();
            $revokeAccess->handle($user, 'password_changed', $user, $request);
        });

        return response()->noContent();
    }

    public function revokeSessions(Request $request, RevokeUserAccess $revokeAccess): Response
    {
        /** @var User $user */
        $user = $request->user();
        $revokeAccess->handle($user, 'user_requested', $user, $request);

        return response()->noContent();
    }
}
