<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevokeUserAccess
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(
        User $user,
        string $cause,
        ?User $actor = null,
        ?Request $request = null,
        bool $revokeAccount = false,
    ): void {
        DB::transaction(function () use ($user, $cause, $actor, $request, $revokeAccount): void {
            DB::table((string) config('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();

            $attributes = ['remember_token' => Str::random(60)];

            if ($revokeAccount) {
                $attributes['revoked_at'] = now();
            }

            $user->forceFill($attributes)->save();

            $this->audit->record('account.access_revoked', $user, $actor, $request, [
                'cause' => $cause,
            ]);
        });

        if ($request?->hasSession() === true) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }
    }
}
