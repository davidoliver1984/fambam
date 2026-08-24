<?php

namespace App\Policies;

use App\Models\PhotoComment;
use App\Models\User;
use App\Tenancy\TenantContext;

class PhotoCommentPolicy
{
    public function __construct(private readonly TenantContext $context) {}

    public function update(User $user, PhotoComment $comment): bool
    {
        return $this->matches($user, $comment) && $comment->author_id === $user->id;
    }

    public function delete(User $user, PhotoComment $comment): bool
    {
        return $this->matches($user, $comment) && ($comment->author_id === $user->id || $this->context->membership()->role->canManageMembers());
    }

    private function matches(User $user, PhotoComment $comment): bool
    {
        return $this->context->isEstablished() && $this->context->membership()->user_id === $user->id && $comment->family_space_id === $this->context->familySpace()->id;
    }
}
