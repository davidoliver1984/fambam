<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AlbumManager
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $space, User $actor, array $input, Request $request): Album
    {
        $this->assertEventBelongsTo($space->id, $input);

        return DB::transaction(function () use ($space, $actor, $input, $request): Album {
            $album = Album::query()->create([
                'family_space_id' => $space->id, 'created_by' => $actor->id,
                'name' => $input['name'], 'description' => $input['description'] ?? null,
                'visibility' => $input['visibility'] ?? AlbumVisibility::FamilySpace->value,
                'event_id' => $input['event_id'] ?? null,
            ]);
            $this->audit->record('album.created', $album, $actor, $request);

            return $album;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Album $album, User $actor, array $input, Request $request): Album
    {
        $this->assertEventBelongsTo($album->family_space_id, $input);

        return DB::transaction(function () use ($album, $actor, $input, $request): Album {
            $visibility = AlbumVisibility::tryFrom((string) ($input['visibility'] ?? ''));
            if ($visibility === AlbumVisibility::Private && $album->visibility !== AlbumVisibility::Private) {
                $album->grants()->delete();
            }
            $album->update($input);
            $this->audit->record('album.updated', $album, $actor, $request);

            return $album->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function grant(Album $album, User $actor, array $input, Request $request): AlbumGrant
    {
        if ($album->visibility !== AlbumVisibility::Selected) {
            $this->fail('Grants are only valid for selected-audience Albums.');
        }
        $membership = FamilySpaceMembership::query()->where('family_space_id', $album->family_space_id)
            ->where('state', MembershipState::Active->value)->find($input['membership_id']);
        if ($membership === null) {
            $this->fail('The selected active membership does not belong to this Family Space.');
        }
        if ($membership->role === FamilySpaceRole::Guest) {
            $this->fail('Guest Album grants are not available in the Phase 6 baseline.');
        }

        return DB::transaction(function () use ($album, $membership, $actor, $input, $request): AlbumGrant {
            $grant = AlbumGrant::query()->updateOrCreate(
                ['album_id' => $album->id, 'family_space_membership_id' => $membership->id],
                ['family_space_id' => $album->family_space_id, 'can_view' => true,
                    'can_contribute' => (bool) $input['can_contribute'], 'granted_by' => $actor->id],
            );
            $this->audit->record('album.grant_saved', $grant, $actor, $request);

            return $grant;
        });
    }

    public function revokeGrant(Album $album, string $membershipId, User $actor, Request $request): void
    {
        DB::transaction(function () use ($album, $membershipId, $actor, $request): void {
            $grant = AlbumGrant::query()->where('album_id', $album->id)
                ->where('family_space_membership_id', $membershipId)->firstOrFail();
            $this->audit->record('album.grant_revoked', $grant, $actor, $request);
            $grant->delete();
        });
    }

    public function addPhoto(Album $album, Photo $photo, User $actor, bool $confirmed, Request $request): AlbumPhoto
    {
        if ($photo->family_space_id !== $album->family_space_id) {
            throw new AuthorizationException;
        }
        $widening = $photo->visibility === PhotoVisibility::Private && $album->visibility !== AlbumVisibility::Private;
        if ($widening && ! $confirmed) {
            $this->fail('Adding this private Photo widens its audience and requires explicit confirmation.');
        }
        if ($widening && ! ($actor->can('update', $photo))) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($album, $photo, $actor, $request): AlbumPhoto {
            Album::query()->whereKey($album->id)->lockForUpdate()->firstOrFail();
            $existing = AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photo->id)->first();
            if ($existing !== null) {
                return $existing;
            }
            $position = ((int) AlbumPhoto::query()->where('album_id', $album->id)->max('position')) + 1;
            $link = AlbumPhoto::query()->create(['family_space_id' => $album->family_space_id,
                'album_id' => $album->id, 'photo_id' => $photo->id, 'position' => $position, 'added_by' => $actor->id]);
            $this->audit->record('album.photo_added', $link, $actor, $request, ['visibility_widened' => $photo->visibility === PhotoVisibility::Private && $album->visibility !== AlbumVisibility::Private]);

            return $link;
        });
    }

    public function removePhoto(Album $album, string $photoId, User $actor, Request $request): void
    {
        DB::transaction(function () use ($album, $photoId, $actor, $request): void {
            Album::query()->whereKey($album->id)->lockForUpdate()->firstOrFail();
            $link = AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photoId)->firstOrFail();
            $this->audit->record('album.photo_removed', $link, $actor, $request);
            $position = $link->position;
            $link->delete();
            AlbumPhoto::query()->where('album_id', $album->id)->where('position', '>', $position)->decrement('position');
        });
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['album' => [$message]]);
    }

    /** @param array<string, mixed> $input */
    private function assertEventBelongsTo(string $familySpaceId, array $input): void
    {
        if (! array_key_exists('event_id', $input) || $input['event_id'] === null) {
            return;
        }
        if (! FamilyEvent::query()->where('family_space_id', $familySpaceId)->whereKey($input['event_id'])->exists()) {
            throw ValidationException::withMessages(['event_id' => ['The selected Event is unavailable.']]);
        }
    }
}
