<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Models\Person;
use App\Models\User;
use App\Tenancy\TenantContext;

class PersonPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->hasDirectoryAccess($user);
    }

    public function create(User $user): bool
    {
        return $this->hasDirectoryAccess($user);
    }

    public function view(User $user, Person $person): bool
    {
        return $this->matchesContext($user, $person) && $this->hasDirectoryAccess($user);
    }

    public function update(User $user, Person $person): bool
    {
        return $this->matchesContext($user, $person)
            && $this->tenantContext->membership()->role->canManageMembers();
    }

    public function propose(User $user, Person $person): bool
    {
        return $this->view($user, $person);
    }

    public function resolveProposal(User $user, Person $person): bool
    {
        return $this->update($user, $person);
    }

    public function proposeAccountLink(User $user, Person $person): bool
    {
        return $this->view($user, $person);
    }

    public function manageAccountLink(User $user, Person $person): bool
    {
        return $this->update($user, $person);
    }

    private function hasDirectoryAccess(User $user): bool
    {
        if (! $this->tenantContext->isEstablished()
            || $this->tenantContext->membership()->user_id !== $user->id) {
            return false;
        }

        return in_array($this->tenantContext->membership()->role, [
            FamilySpaceRole::Owner,
            FamilySpaceRole::Administrator,
            FamilySpaceRole::Member,
        ], true);
    }

    private function matchesContext(User $user, Person $person): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && $this->tenantContext->familySpace()->id === $person->family_space_id;
    }
}
