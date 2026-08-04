<?php

namespace App\Models;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use Database\Factories\FamilySpaceMembershipFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property FamilySpaceRole $role
 * @property MembershipState $state
 */
#[Fillable([
    'family_space_id',
    'user_id',
    'role',
    'state',
    'invitation_id',
    'removed_at',
    'removed_by',
])]
class FamilySpaceMembership extends Model
{
    /** @use HasFactory<FamilySpaceMembershipFactory> */
    use HasFactory, HasUlids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<FamilySpace, $this> */
    public function familySpace(): BelongsTo
    {
        return $this->belongsTo(FamilySpace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Invitation, $this> */
    public function invitation(): BelongsTo
    {
        return $this->belongsTo(Invitation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'removed_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => FamilySpaceRole::class,
            'state' => MembershipState::class,
            'removed_at' => 'immutable_datetime',
        ];
    }
}
