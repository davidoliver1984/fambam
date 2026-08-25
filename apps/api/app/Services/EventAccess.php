<?php

namespace App\Services;

use App\Enums\FamilySpaceRole;
use App\Enums\GuestParticipation;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\EventAdmission;
use App\Models\FamilyEvent;
use App\Models\FamilySpaceMembership;
use Illuminate\Database\Eloquent\Builder;

class EventAccess
{
    public function hasValidAdmission(FamilyEvent|string $event, FamilySpaceMembership $membership): bool
    {
        $eventId = $event instanceof FamilyEvent ? $event->id : $event;

        return EventAdmission::query()
            ->where('event_id', $eventId)
            ->where('family_space_id', $membership->family_space_id)
            ->where('family_space_membership_id', $membership->id)
            ->whereNull('revoked_at')
            ->where('admitted_at', '>', now()->subDays((int) config('events.admission_lifetime_days')))
            ->whereHas('event', fn ($query) => $query->whereNull('deleted_at'))
            ->exists();
    }

    public function guestMayViewAlbum(Album $album, FamilySpaceMembership $membership): bool
    {
        if ($membership->role !== FamilySpaceRole::Guest
            || $album->family_space_id !== $membership->family_space_id || $album->event_id === null
            || ! $this->hasValidAdmission($album->event_id, $membership)) {
            return false;
        }

        return $album->guest_participation->canView() || $this->grant($album, $membership, false);
    }

    public function guestMayContributeToAlbum(Album $album, FamilySpaceMembership $membership): bool
    {
        if ($membership->role !== FamilySpaceRole::Guest
            || $album->family_space_id !== $membership->family_space_id || $album->event_id === null
            || ! $this->hasValidAdmission($album->event_id, $membership)) {
            return false;
        }

        return $album->guest_participation === GuestParticipation::Contribute
            || $this->grant($album, $membership, true);
    }

    public function guestMayDownloadOriginal(Album $album, FamilySpaceMembership $membership): bool
    {
        return $membership->role === FamilySpaceRole::Guest
            && $album->family_space_id === $membership->family_space_id
            && $album->event_id !== null
            && $album->guest_participation->canView()
            && $this->hasValidAdmission($album->event_id, $membership);
    }

    /**
     * @param  Builder<Album>  $query
     * @return Builder<Album>
     */
    public function scopeAlbumsForGuest(Builder $query, FamilySpaceMembership $membership): Builder
    {
        if ($membership->role !== FamilySpaceRole::Guest) {
            return $query->whereRaw('1 = 0');
        }

        $cutoff = now()->subDays((int) config('events.admission_lifetime_days'));

        return $query
            ->whereNotNull('event_id')
            ->whereHas('event')
            ->whereHas('event.admissions', fn (Builder $admissions) => $admissions
                ->where('family_space_membership_id', $membership->id)
                ->whereNull('revoked_at')
                ->where('admitted_at', '>', $cutoff))
            ->where(function (Builder $access) use ($membership): void {
                $access->whereIn('guest_participation', [
                    GuestParticipation::View->value,
                    GuestParticipation::Contribute->value,
                ])->orWhereHas('grants', fn (Builder $grant) => $grant
                    ->where('family_space_membership_id', $membership->id)
                    ->where('can_view', true));
            });
    }

    private function grant(Album $album, FamilySpaceMembership $membership, bool $contribute): bool
    {
        return AlbumGrant::query()->where('album_id', $album->id)
            ->where('family_space_membership_id', $membership->id)
            ->where('can_view', true)
            ->when($contribute, fn ($query) => $query->where('can_contribute', true))
            ->exists();
    }
}
