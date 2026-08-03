<?php

namespace App\Actions\Fortify;

use App\Models\User;
use App\Services\RevokeUserAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(
        private readonly RevokeUserAccess $revokeAccess,
        private readonly Request $request,
    ) {}

    /**
     * Validate and reset the user's forgotten password.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => Hash::make($input['password']),
            ])->save();

            $this->revokeAccess->handle($user, 'password_reset', request: $this->request);
        });
    }
}
