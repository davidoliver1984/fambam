<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        throw ValidationException::withMessages([
            'email' => 'Accounts can only be created through a valid invitation.',
        ]);
    }
}
