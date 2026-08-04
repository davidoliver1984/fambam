<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Models\FamilySpaceMembership;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MembershipInvitationAcceptor
{
    /** @return array{membership: FamilySpaceMembership, reactivated: bool}|null */
    public function accept(Invitation $invitation, User $user): ?array
    {
        return DB::getDriverName() === 'pgsql'
            ? $this->acceptWithPostgres($invitation, $user)
            : $this->acceptWithPortableFallback($invitation, $user);
    }

    /** @return array{membership: FamilySpaceMembership, reactivated: bool}|null */
    private function acceptWithPostgres(Invitation $invitation, User $user): ?array
    {
        $result = DB::selectOne(<<<'SQL'
INSERT INTO family_space_memberships (
    id, family_space_id, user_id, role, state, invitation_id,
    removed_at, removed_by, created_at, updated_at
) VALUES (?, ?, ?, ?, 'active', ?, NULL, NULL, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
ON CONFLICT (family_space_id, user_id) DO UPDATE SET
    role = EXCLUDED.role,
    state = 'active',
    invitation_id = EXCLUDED.invitation_id,
    removed_at = NULL,
    removed_by = NULL,
    updated_at = CURRENT_TIMESTAMP
WHERE family_space_memberships.state = 'removed'
RETURNING id, (xmax = 0) AS inserted
SQL, [
            (string) Str::ulid(),
            $invitation->family_space_id,
            $user->id,
            $invitation->role->value,
            $invitation->id,
        ]);

        if ($result === null) {
            return null;
        }

        return [
            'membership' => FamilySpaceMembership::query()->findOrFail($result->id),
            'reactivated' => ! (bool) $result->inserted,
        ];
    }

    /** @return array{membership: FamilySpaceMembership, reactivated: bool}|null */
    private function acceptWithPortableFallback(Invitation $invitation, User $user): ?array
    {
        $existing = FamilySpaceMembership::query()
            ->where('family_space_id', $invitation->family_space_id)
            ->where('user_id', $user->id)
            ->first();
        $membershipId = (string) Str::ulid();
        $now = now();

        DB::statement(<<<'SQL'
INSERT INTO family_space_memberships (
    id, family_space_id, user_id, role, state, invitation_id,
    removed_at, removed_by, created_at, updated_at
) VALUES (?, ?, ?, ?, 'active', ?, NULL, NULL, ?, ?)
ON CONFLICT (family_space_id, user_id) DO UPDATE SET
    role = excluded.role,
    state = 'active',
    invitation_id = excluded.invitation_id,
    removed_at = NULL,
    removed_by = NULL,
    updated_at = excluded.updated_at
WHERE family_space_memberships.state = 'removed'
SQL, [
            $membershipId,
            $invitation->family_space_id,
            $user->id,
            $invitation->role->value,
            $invitation->id,
            $now,
            $now,
        ]);

        $membership = FamilySpaceMembership::query()
            ->where('family_space_id', $invitation->family_space_id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($membership->invitation_id !== $invitation->id || $membership->state !== MembershipState::Active) {
            return null;
        }

        return ['membership' => $membership, 'reactivated' => $existing !== null];
    }
}
