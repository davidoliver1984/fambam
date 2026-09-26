<?php

namespace App\Services;

use App\Enums\MembershipState;
use App\Models\FamilySpaceMembership;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class ActorPresentationService
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PresentationThumbnailService $thumbnails,
    ) {}

    /**
     * @param  Collection<int, User>  $users
     * @return array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>
     */
    public function forUsers(Collection $users, User $viewer): array
    {
        $users = $users->unique('id')->values();
        if ($users->isEmpty()) {
            return [];
        }

        $familySpaceId = $this->tenantContext->familySpace()->id;
        $activeUserIds = FamilySpaceMembership::query()
            ->where('family_space_id', $familySpaceId)
            ->where('state', MembershipState::Active->value)
            ->whereIn('user_id', $users->pluck('id'))
            ->pluck('user_id');
        $links = PersonAccountLink::query()
            ->where('family_space_id', $familySpaceId)
            ->whereIn('user_id', $activeUserIds)
            ->with('person:id,family_space_id,preferred_name')
            ->get()
            ->filter(fn (PersonAccountLink $link): bool => $link->person !== null
                && $link->person->family_space_id === $familySpaceId
                && Gate::forUser($viewer)->allows('view', $link->person))
            ->keyBy('user_id');
        $portraitUrls = $this->thumbnails->forPeople($links->pluck('person_id')->values()->all(), $viewer);

        return $users->mapWithKeys(function (User $user) use ($links, $portraitUrls): array {
            $link = $links->get($user->id);
            $displayName = $link === null ? $user->name : $link->person->preferred_name;
            $personId = $link?->person_id;

            return [$user->id => [
                'display_name' => $displayName,
                'person_id' => $personId,
                'initials' => $this->initials($displayName),
                'portrait_thumbnail_url' => $personId === null ? null : ($portraitUrls[$personId] ?? null),
            ]];
        })->all();
    }

    /** @return array{display_name: string, person_id: null, initials: string, portrait_thumbnail_url: null} */
    public function formerMember(): array
    {
        return [
            'display_name' => 'Former family member',
            'person_id' => null,
            'initials' => 'FM',
            'portrait_thumbnail_url' => null,
        ];
    }

    private function initials(string $name): string
    {
        return collect(preg_split('/\s+/', trim($name)) ?: [])->filter()->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    }
}
