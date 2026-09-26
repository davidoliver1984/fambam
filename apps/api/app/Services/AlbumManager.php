<?php

namespace App\Services;

use App\Enums\AlbumVisibility;
use App\Enums\FamilyActivityType;
use App\Enums\FamilySpaceRole;
use App\Enums\MembershipState;
use App\Enums\PhotoVisibility;
use App\Models\Album;
use App\Models\AlbumGrant;
use App\Models\AlbumPhoto;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Person;
use App\Models\Photo;
use App\Models\Tag;
use App\Models\User;
use App\Stories\MentionAuthorizer;
use App\Stories\RichTextFieldWriter;
use App\Tenancy\TenantOperationContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AlbumManager
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly FamilyActivityRecorder $activities,
        private readonly EventContributionNotifier $notifications,
        private readonly RichTextFieldWriter $richText,
        private readonly MentionAuthorizer $mentionAuthorizer,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(FamilySpace $space, User $actor, array $input, Request $request): Album
    {
        $this->assertEventBelongsTo($space->id, $input);
        $this->assertDateRange($input['starts_on'] ?? null, $input['ends_on'] ?? null);

        return DB::transaction(function () use ($space, $actor, $input, $request): Album {
            $album = Album::query()->create([
                'family_space_id' => $space->id, 'created_by' => $actor->id,
                'name' => $input['name'], 'description' => $input['description'] ?? null,
                'visibility' => $input['visibility'] ?? AlbumVisibility::FamilySpace->value,
                'event_id' => $input['event_id'] ?? null,
                'guest_participation' => ($input['event_id'] ?? null) === null ? 'none' : ($input['guest_participation'] ?? 'none'),
                'starts_on' => $input['starts_on'] ?? null, 'ends_on' => $input['ends_on'] ?? null,
                'location' => $this->cleanLocation($input['location'] ?? null),
            ]);
            if (array_key_exists('description', $input)) {
                $description = $this->richText->synchronize($album, 'album_description_mentions', 'album_id',
                    $input['description'], $this->mentionAuthorizer->for($actor, $album));
                $album->update(['description' => $description]);
            }
            $this->syncTags($album, $actor, $input['tags'] ?? []);
            $this->syncPeople($album, $actor, $input['person_ids'] ?? []);
            if (($input['cover_photo_id'] ?? null) !== null) {
                $this->setCover($album, $actor, $input['cover_photo_id'],
                    (bool) ($input['confirm_visibility_widening'] ?? false), null, null, $request);
            }
            $this->audit->record('album.created', $album, $actor, $request);
            $this->activities->record(
                $album->family_space_id,
                $actor->id,
                FamilyActivityType::AlbumCreated,
                subjectAlbumId: $album->id,
            );

            return $album->refresh();
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Album $album, User $actor, array $input, Request $request): Album
    {
        $this->assertEventBelongsTo($album->family_space_id, $input);
        $this->assertDateRange(
            array_key_exists('starts_on', $input) ? $input['starts_on'] : $album->starts_on?->format('Y-m-d'),
            array_key_exists('ends_on', $input) ? $input['ends_on'] : $album->ends_on?->format('Y-m-d'),
        );

        return DB::transaction(function () use ($album, $actor, $input, $request): Album {
            $eventId = array_key_exists('event_id', $input) ? $input['event_id'] : $album->event_id;
            if ($eventId === null) {
                $input['guest_participation'] = 'none';
            }
            $visibility = AlbumVisibility::tryFrom((string) ($input['visibility'] ?? ''));
            if ($visibility === AlbumVisibility::Private && $album->visibility !== AlbumVisibility::Private) {
                $album->grants()->delete();
            }
            $attributes = array_diff_key($input, array_flip(['tags', 'person_ids']));
            if (array_key_exists('location', $attributes)) {
                $attributes['location'] = $this->cleanLocation($attributes['location']);
            }
            $album->update($attributes);
            if (array_key_exists('description', $input)) {
                $description = $this->richText->synchronize($album, 'album_description_mentions', 'album_id',
                    $input['description'], $this->mentionAuthorizer->for($actor, $album));
                $album->update(['description' => $description]);
            }
            if (array_key_exists('tags', $input)) {
                $this->syncTags($album, $actor, $input['tags']);
            }
            if (array_key_exists('person_ids', $input)) {
                $this->syncPeople($album, $actor, $input['person_ids']);
            }
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
        if ($membership->role === FamilySpaceRole::Guest && $album->event_id === null) {
            $this->fail('Guest Album grants require an Event-linked Album.');
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
            $this->audit->record('album.photo_added', $link, $actor, $request, [
                'album_id' => $album->id,
                'photo_id' => $photo->id,
                'visibility_widened' => $photo->visibility === PhotoVisibility::Private && $album->visibility !== AlbumVisibility::Private,
            ]);
            $this->activities->record(
                $album->family_space_id,
                $actor->id,
                FamilyActivityType::PhotosAddedToAlbum,
                subjectAlbumId: $album->id,
                photoIds: [$photo->id],
            );
            $this->notifications->dispatch($album, $photo, TenantOperationContext::fromRequest($album->familySpace, $actor, $request));

            return $link;
        });
    }

    public function removePhoto(Album $album, string $photoId, User $actor, Request $request): void
    {
        DB::transaction(function () use ($album, $photoId, $actor, $request): void {
            $locked = Album::query()->whereKey($album->id)->lockForUpdate()->firstOrFail();
            $link = AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photoId)->firstOrFail();
            if ($locked->cover_photo_id === $photoId) {
                if (! $request->boolean('confirm_cover_removal')) {
                    $this->fail('Removing this Photo also removes the Album cover; confirm that change.');
                }
                $locked->update($this->clearedCover());
            }
            $this->audit->record('album.photo_removed', $link, $actor, $request, [
                'album_id' => $album->id,
                'photo_id' => $photoId,
            ]);
            $position = $link->position;
            $link->delete();
            AlbumPhoto::query()->where('album_id', $album->id)->where('position', '>', $position)->decrement('position');
        });
    }

    public function setCover(
        Album $album, User $actor, ?string $photoId, bool $confirmed,
        ?float $focalX, ?float $focalY, Request $request,
    ): Album {
        return DB::transaction(function () use ($album, $actor, $photoId, $confirmed, $focalX, $focalY, $request): Album {
            $locked = Album::query()->whereKey($album->id)->lockForUpdate()->firstOrFail();
            if (! $actor->can('update', $locked)) {
                throw new AuthorizationException;
            }
            if ($photoId === null) {
                $locked->update($this->clearedCover() + ['current_cover_intent_id' => null]);
            } else {
                $photo = Photo::query()->where('family_space_id', $locked->family_space_id)->findOrFail($photoId);
                if (! $actor->can('view', $photo)) {
                    throw new AuthorizationException;
                }
                $this->addPhoto($locked, $photo, $actor, $confirmed, $request);
                $locked->update(['cover_photo_id' => $photo->id,
                    'cover_focal_x' => $focalX ?? 0.5, 'cover_focal_y' => $focalY ?? 0.5,
                    'current_cover_intent_id' => null]);
            }
            $this->audit->record('album.cover_changed', $locked, $actor, $request);

            return $locked->refresh();
        });
    }

    public function beginCoverIntent(Album $album, MediaUpload $upload, User $actor): bool
    {
        $locked = Album::query()->whereKey($album->id)->lockForUpdate()->firstOrFail();
        if ($upload->target_album_id !== $locked->id || $upload->user_id !== $actor->id) {
            throw new AuthorizationException;
        }
        if (! $actor->can('update', $locked)) {
            return false;
        }
        $locked->update(['current_cover_intent_id' => $upload->id]);

        return true;
    }

    public function finalizeCoverIntent(MediaUpload $upload, Photo $photo): void
    {
        if ($upload->target_album_id === null) {
            return;
        }
        $album = Album::query()->whereKey($upload->target_album_id)->lockForUpdate()->first();
        if ($album === null || $album->current_cover_intent_id !== $upload->id) {
            return;
        }
        $membership = FamilySpaceMembership::query()->where('family_space_id', $album->family_space_id)
            ->where('user_id', $upload->user_id)->where('state', MembershipState::Active->value)->first();
        $canManage = $membership !== null && $membership->role !== FamilySpaceRole::Guest
            && ($membership->role->canManageMembers() || $album->created_by === $upload->user_id);
        $isMember = ! $photo->trashed() && $photo->family_space_id === $album->family_space_id
            && AlbumPhoto::query()->where('album_id', $album->id)->where('photo_id', $photo->id)->exists();
        $album->update(($canManage && $isMember
            ? ['cover_photo_id' => $photo->id, 'cover_focal_x' => 0.5, 'cover_focal_y' => 0.5]
            : []) + ['current_cover_intent_id' => null]);
    }

    public function clearCoverIntentIfCurrent(MediaUpload $upload): void
    {
        if ($upload->target_album_id === null) {
            return;
        }
        $album = Album::query()->whereKey($upload->target_album_id)->lockForUpdate()->first();
        if ($album !== null && $album->current_cover_intent_id === $upload->id) {
            $album->update(['current_cover_intent_id' => null]);
        }
    }

    /** @return array<string, null> */
    private function clearedCover(): array
    {
        return ['cover_photo_id' => null, 'cover_focal_x' => null, 'cover_focal_y' => null];
    }

    /** @param list<string> $labels */
    private function syncTags(Album $album, User $actor, array $labels): void
    {
        $normalized = [];
        foreach ($labels as $label) {
            $display = preg_replace('/\s+/u', ' ', trim($label)) ?? '';
            if ($display !== '') {
                $normalized[mb_strtolower($display)] ??= $display;
            }
        }
        $tagIds = [];
        foreach ($normalized as $key => $display) {
            $tag = Tag::query()->firstOrCreate(
                ['family_space_id' => $album->family_space_id, 'normalized_label' => $key],
                ['label' => $display, 'created_by' => $actor->id],
            );
            $tagIds[$tag->id] = ['family_space_id' => $album->family_space_id,
                'added_by' => $actor->id, 'created_at' => now()];
        }
        $album->tags()->sync($tagIds);
    }

    /** @param list<string> $personIds */
    private function syncPeople(Album $album, User $actor, array $personIds): void
    {
        $ids = array_values(array_unique($personIds));
        $available = Person::query()->where('family_space_id', $album->family_space_id)
            ->whereIn('id', $ids)->count();
        if ($available !== count($ids)) {
            $this->fail('One or more selected People are unavailable in this Family Space.');
        }
        DB::table('album_people')->where('album_id', $album->id)->whereNotIn('person_id', $ids)->delete();
        foreach ($ids as $id) {
            DB::table('album_people')->insertOrIgnore(['id' => (string) Str::ulid(),
                'family_space_id' => $album->family_space_id, 'album_id' => $album->id,
                'person_id' => $id, 'added_by' => $actor->id, 'created_at' => now()]);
        }
    }

    private function assertDateRange(?string $start, ?string $end): void
    {
        if ($start !== null && $end !== null && $end < $start) {
            throw ValidationException::withMessages(['ends_on' => ['The end date must not precede the start date.']]);
        }
    }

    private function cleanLocation(?string $location): ?string
    {
        return $location === null ? null : (trim($location) ?: null);
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
