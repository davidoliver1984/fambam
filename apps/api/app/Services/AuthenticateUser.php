<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Support\Str;

class AuthenticateUser
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly string $dummyPasswordHash,
    ) {}

    public function attempt(string $email, string $password): ?User
    {
        $user = User::query()->where('email', Str::lower($email))->first();
        $storedHash = $user === null ? $this->dummyPasswordHash : $user->password;
        $passwordMatches = $this->hasher->check(
            $password,
            $storedHash,
        );

        if ($user === null || $user->revoked_at !== null || ! $passwordMatches) {
            return null;
        }

        if (config('hashing.rehash_on_login') && $this->hasher->needsRehash($user->password)) {
            $user->forceFill(['password' => $this->hasher->make($password)])->save();
        }

        return $user;
    }
}
