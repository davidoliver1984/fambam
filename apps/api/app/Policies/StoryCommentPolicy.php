<?php

namespace App\Policies;

use App\Models\Story;
use App\Models\StoryComment;
use App\Models\User;
use App\Tenancy\TenantContext;

final class StoryCommentPolicy
{
    public function __construct(private readonly TenantContext $context, private readonly StoryPolicy $stories) {}

    public function create(User $user, Story $story): bool
    {
        return $this->stories->view($user, $story);
    }

    public function update(User $user, StoryComment $comment): bool
    {
        return ! $comment->trashed() && $comment->author_id === $user->id
            && $this->stories->view($user, $comment->story()->firstOrFail());
    }

    public function delete(User $user, StoryComment $comment): bool
    {
        return ! $comment->trashed()
            && $this->stories->view($user, $comment->story()->firstOrFail())
            && ($comment->author_id === $user->id || $this->context->membership()->role->canManageMembers());
    }
}
