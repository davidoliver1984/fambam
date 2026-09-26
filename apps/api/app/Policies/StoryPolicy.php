<?php

namespace App\Policies;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Story;
use App\Models\User;
use App\Tenancy\TenantContext;

final class StoryPolicy
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PersonPolicy $people,
        private readonly AlbumPolicy $albums,
        private readonly FamilyEventPolicy $events,
        private readonly PhotoPolicy $photos,
    ) {}

    public function view(User $user, Story $story): bool
    {
        if ($story->trashed() || ! $this->matches($user, $story)) {
            return false;
        }

        return $this->subjectVisible($user, $story);
    }

    private function subjectVisible(User $user, Story $story): bool
    {
        return match (true) {
            $story->person_id !== null => $this->personVisible($user, $story),
            $story->album_id !== null => ($album = $story->relationLoaded('album') ? $story->album : $story->album()->first()) !== null
                && $this->albums->view($user, $album),
            $story->event_id !== null => ($event = $story->relationLoaded('event') ? $story->event : $story->event()->first()) !== null
                && $this->events->view($user, $event),
            $story->photo_id !== null => ($photo = $story->relationLoaded('photo') ? $story->photo : $story->photo()->first()) !== null
                && $this->photos->view($user, $photo),
            default => false,
        };
    }

    public function createForSubject(User $user, object $subject): bool
    {
        return match (true) {
            $subject instanceof Person => $this->people->view($user, $subject),
            $subject instanceof Album => $this->albums->contribute($user, $subject),
            $subject instanceof FamilyEvent => $this->events->view($user, $subject),
            $subject instanceof Photo => $this->photos->authorStory($user, $subject),
            default => false,
        };
    }

    public function update(User $user, Story $story): bool
    {
        return $this->view($user, $story) && $story->author_id === $user->id;
    }

    public function delete(User $user, Story $story): bool
    {
        return $this->view($user, $story)
            && ($story->author_id === $user->id || $this->context->membership()->role->canManageMembers());
    }

    public function restore(User $user, Story $story): bool
    {
        return $story->trashed() && $this->matches($user, $story)
            && $this->subjectVisible($user, $story)
            && ($story->author_id === $user->id || $this->context->membership()->role->canManageMembers());
    }

    private function matches(User $user, Story $story): bool
    {
        return $this->context->isEstablished()
            && $this->context->membership()->user_id === $user->id
            && $story->family_space_id === $this->context->familySpace()->id;
    }

    private function personVisible(User $user, Story $story): bool
    {
        $person = $story->relationLoaded('person') ? $story->person : $story->person()->first();
        if ($person === null) {
            return false;
        }
        if ($this->people->view($user, $person)) {
            return true;
        }

        return $person->photoPeople()->where('status', 'approved')->with('photo')->get()
            ->contains(fn ($association): bool => $association->photo !== null
                && $this->photos->view($user, $association->photo));
    }
}
