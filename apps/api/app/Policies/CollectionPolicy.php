<?php

namespace App\Policies;

use App\Enums\MembershipState;
use App\Models\Collection;
use App\Models\User;
use App\Tenancy\TenantContext;

class CollectionPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && $this->tenantContext->membership()->state === MembershipState::Active;
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Collection $collection): bool
    {
        return $this->viewAny($user)
            && $collection->family_space_id === $this->tenantContext->familySpace()->id
            && $collection->owner_user_id === $user->id;
    }

    public function update(User $user, Collection $collection): bool
    {
        return $this->view($user, $collection);
    }

    public function delete(User $user, Collection $collection): bool
    {
        return $this->view($user, $collection);
    }
}
