<?php

namespace App\Policies;

use App\Models\PhotoStory;
use App\Models\User;
use App\Tenancy\TenantContext;

class PhotoStoryPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function update(User $user, PhotoStory $story): bool
    {
        return $this->matches($user, $story) && $story->author_id === $user->id;
    }

    public function delete(User $user, PhotoStory $story): bool
    {
        return $this->matches($user, $story) && ($story->author_id === $user->id || $this->context->membership()->role->canManageMembers());
    }

    private function matches(User $user, PhotoStory $story): bool
    {
        return $this->context->isEstablished() && $this->context->membership()->user_id === $user->id && $story->family_space_id === $this->context->familySpace()->id;
    }
}
