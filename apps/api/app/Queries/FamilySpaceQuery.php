<?php

namespace App\Queries;

use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Models\FamilySpace;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FamilySpaceQuery
{
    /** @return Builder<FamilySpace> */
    public function accessibleTo(User $user): Builder
    {
        return FamilySpace::query()
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('state', MembershipState::Active->value));
    }

    /** @return Collection<int, FamilySpace> */
    public function listAccessibleTo(User $user): Collection
    {
        return $this->accessibleTo($user)
            ->whereHas('memberships', fn (Builder $query) => $query
                ->where('user_id', $user->id)
                ->where('state', MembershipState::Active->value)
                ->where('role', '!=', FamilySpaceRole::Guest->value))
            ->with(['memberships' => function ($query) use ($user): void {
                $query->where('user_id', $user->id)
                    ->where('state', MembershipState::Active->value);
            }])
            ->orderBy('name')
            ->get();
    }

    public function resolveAccessibleSlug(User $user, string $slug): FamilySpace
    {
        return $this->accessibleTo($user)
            ->where('slug', $slug)
            ->first()
            ?? throw new NotFoundHttpException;
    }
}
