<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Models\FamilyEvent;
use App\Models\User;
use App\Tenancy\TenantContext;

class FamilyEventPolicy
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    public function viewAny(User $user): bool
    {
        return $this->ordinaryMember($user);
    }

    public function create(User $user): bool
    {
        return $this->ordinaryMember($user);
    }

    public function view(User $user, FamilyEvent $event): bool
    {
        return $this->ordinaryMember($user) && $this->sameFamily($event);
    }

    public function update(User $user, FamilyEvent $event): bool
    {
        return $this->view($user, $event)
            && ($this->tenantContext->membership()->role->canManageMembers()
                || $event->created_by === $user->id);
    }

    private function ordinaryMember(User $user): bool
    {
        return $this->tenantContext->isEstablished()
            && $this->tenantContext->membership()->user_id === $user->id
            && in_array($this->tenantContext->membership()->role, [
                FamilySpaceRole::Owner,
                FamilySpaceRole::Administrator,
                FamilySpaceRole::Member,
            ], true);
    }

    private function sameFamily(FamilyEvent $event): bool
    {
        return $event->family_space_id === $this->tenantContext->familySpace()->id;
    }
}
