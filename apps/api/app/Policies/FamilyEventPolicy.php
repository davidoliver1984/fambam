<?php

namespace App\Policies;

use App\Enums\FamilySpaceRole;
use App\Models\FamilyEvent;
use App\Models\User;
use App\Services\EventAccess;
use App\Tenancy\TenantContext;

class FamilyEventPolicy
{
    public function __construct(private readonly TenantContext $tenantContext, private readonly EventAccess $access) {}

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
        if (! $this->sameFamily($event) || $this->tenantContext->membership()->user_id !== $user->id) {
            return false;
        }

        return $this->ordinaryMember($user)
            || ($this->tenantContext->membership()->role === FamilySpaceRole::Guest
                && $this->access->hasValidAdmission($event, $this->tenantContext->membership()));
    }

    public function manageAdmissions(User $user, FamilyEvent $event): bool
    {
        return $this->sameFamily($event) && $this->ordinaryMember($user)
            && $this->tenantContext->membership()->role->canManageMembers();
    }

    public function delete(User $user, FamilyEvent $event): bool
    {
        return $this->manageAdmissions($user, $event);
    }

    public function restore(User $user, FamilyEvent $event): bool
    {
        return $this->manageAdmissions($user, $event);
    }

    public function reviewDuplicates(User $user, FamilyEvent $event): bool
    {
        return $this->manageAdmissions($user, $event);
    }

    public function manageExports(User $user, FamilyEvent $event): bool
    {
        return $this->manageAdmissions($user, $event) && ! $event->trashed();
    }

    public function update(User $user, FamilyEvent $event): bool
    {
        return $this->view($user, $event)
            && $this->tenantContext->membership()->role !== FamilySpaceRole::Guest
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
