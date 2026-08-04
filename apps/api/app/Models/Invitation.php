<?php

namespace App\Models;

use App\Enums\FamilySpaceRole;
use App\Enums\InvitationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\InvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property InvitationStatus $status
 * @property FamilySpaceRole $role
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $accepted_at
 * @property CarbonImmutable|null $revoked_at
 * @property-read FamilySpace $familySpace
 */
#[Fillable([
    'family_space_id',
    'email',
    'role',
    'token_hash',
    'invited_by',
    'status',
    'expires_at',
    'accepted_at',
    'revoked_at',
])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    /** @use HasFactory<InvitationFactory> */
    use HasFactory;

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return HasMany<InvitationClaim, $this> */
    public function claims(): HasMany
    {
        return $this->hasMany(InvitationClaim::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvitationStatus::class,
            'role' => FamilySpaceRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
