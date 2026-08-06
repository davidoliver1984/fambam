<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property Carbon|null $email_verified_at
 * @property bool $can_create_family_spaces
 * @property Carbon|null $revoked_at
 */
#[Fillable(['name', 'email', 'password', 'timezone'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** @return HasMany<FamilySpaceMembership, $this> */
    public function familySpaceMemberships(): HasMany
    {
        return $this->hasMany(FamilySpaceMembership::class);
    }

    /** @return HasMany<PersonAccountLink, $this> */
    public function personAccountLinks(): HasMany
    {
        return $this->hasMany(PersonAccountLink::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'can_create_family_spaces' => 'boolean',
            'revoked_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
