<?php

namespace App\Http\Controllers;

use App\Http\Requests\InitiateMediaUploadRequest;
use App\Http\Requests\SetAlbumCoverRequest;
use App\Http\Requests\StoreAlbumGrantRequest;
use App\Http\Requests\StoreAlbumPhotoRequest;
use App\Http\Requests\StoreAlbumRequest;
use App\Http\Requests\UpdateAlbumRequest;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Services\AlbumManager;
use App\Services\MediaUploadManager;
use App\Stories\RichTextPresenter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AlbumController extends Controller
{
    public function __construct(
        private readonly AlbumQuery $albums,
        private readonly AlbumManager $manager,
        private readonly MediaUploadManager $uploads,
        private readonly RichTextPresenter $presenter,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Album::class);

        $albums = $this->albums->listVisibleTo($request->user());
        $this->loadPresentation($albums);

        return response()->json(['data' => $albums->map(fn (Album $album): array => $this->payload($album, false))]);
    }

    public function store(FamilySpace $familySpace, StoreAlbumRequest $request): JsonResponse
    {
        Gate::authorize('create', Album::class);

        return response()->json(['data' => $this->payload($this->manager->create($familySpace, $request->user(), $request->validated(), $request))], 201);
    }

    public function show(FamilySpace $familySpace, string $album, Request $request): JsonResponse
    {
        $target = $this->albums->findVisibleTo($request->user(), $album);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->payload($target)]);
    }

    public function update(FamilySpace $familySpace, string $album, UpdateAlbumRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('update', $target);

        return response()->json(['data' => $this->payload($this->manager->update($target, $request->user(), $request->validated(), $request))]);
    }

    public function destroy(FamilySpace $familySpace, string $album, Request $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('delete', $target);
        $this->manager->delete($target, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function grant(FamilySpace $familySpace, string $album, StoreAlbumGrantRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('manageGrants', $target);

        return response()->json(['data' => $this->manager->grant($target, $request->user(), $request->validated(), $request)], 201);
    }

    public function revokeGrant(FamilySpace $familySpace, string $album, string $membership, Request $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('manageGrants', $target);
        $this->manager->revokeGrant($target, $membership, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function addPhoto(FamilySpace $familySpace, string $album, StoreAlbumPhotoRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('addPhoto', $target);
        $photo = Photo::query()->where('family_space_id', $familySpace->id)->findOrFail($request->validated('photo_id'));
        $link = $this->manager->addPhoto($target, $photo, $request->user(), (bool) $request->validated('confirm_visibility_widening', false), $request);

        return response()->json(['data' => $link], 201);
    }

    public function removePhoto(FamilySpace $familySpace, string $album, string $photo, Request $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('removePhoto', $target);
        $this->manager->removePhoto($target, $photo, $request->user(), $request);

        return response()->json(null, 204);
    }

    public function setCover(FamilySpace $familySpace, string $album, SetAlbumCoverRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('update', $target);
        $input = $request->validated();
        $updated = $this->manager->setCover($target, $request->user(), $input['photo_id'],
            (bool) ($input['confirm_visibility_widening'] ?? false),
            isset($input['focal_x']) ? (float) $input['focal_x'] : null,
            isset($input['focal_y']) ? (float) $input['focal_y'] : null, $request);

        return response()->json(['data' => $this->payload($updated)]);
    }

    public function initiateUpload(FamilySpace $familySpace, string $album, InitiateMediaUploadRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('contribute', $target);
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_if($key === '' || strlen($key) > 100, 422, 'A valid Idempotency-Key header is required.');
        $input = $request->validated();
        $coverRequested = (bool) ($input['as_cover'] ?? false);
        $coverAllowed = $coverRequested && $request->user()->can('update', $target);
        $coverAccepted = false;
        $result = DB::transaction(function () use ($familySpace, $target, $input, $request, $key, $coverAllowed, &$coverAccepted) {
            $result = $this->uploads->initiate($familySpace, $request->user(), $key, $input, $request, $target->id);
            if ($coverAllowed) {
                $coverAccepted = $result->created
                    ? $this->manager->beginCoverIntent($target, $result->upload, $request->user())
                    : Album::query()->whereKey($target->id)
                        ->where('current_cover_intent_id', $result->upload->id)->exists();
            }

            return $result;
        });

        return response()->json(['data' => ['id' => $result->upload->id, 'state' => $result->upload->state->value,
            'target_album_id' => $target->id, 'cover_intent_accepted' => $coverAccepted,
            'cover_intent_reason' => $coverRequested && ! $coverAccepted
                ? ($coverAllowed ? 'cover_intent_not_current' : 'album_update_forbidden') : null,
            'upload_authorization' => $result->authorization === null ? null : [
                'url' => $result->authorization->url, 'method' => 'PUT', 'headers' => $result->authorization->headers,
                'expires_at' => $result->authorization->expiresAt->toAtomString()]]], $result->created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function payload(Album $album, bool $renderDescription = true): array
    {
        $album->loadMissing(['creator:id,name', 'event:id,name,starts_on', 'tags:id,label', 'coverPhoto.mediaUpload',
            'albumPhotos' => fn ($query) => $query->whereHas('photo')->with('photo.mediaUpload'),
            'grants.membership.user:id,name']);
        if (Gate::allows('viewAny', Person::class)) {
            $album->loadMissing('people:id,preferred_name');
        }
        $photoCount = $album->getAttribute('album_photos_count');

        return ['id' => $album->id, 'name' => $album->name, 'description' => $album->description_plain_text,
            'description_document' => $album->description,
            'description_html' => $renderDescription
                ? $this->presenter->html($album->description, $album, 'album_description_mentions',
                    'album_id', $this->familySlug(), $this->actor(), $album)
                : null,
            'visibility' => $album->visibility->value, 'created_by' => $album->created_by,
            'creator' => $album->creator === null ? null : ['id' => $album->creator->id, 'name' => $album->creator->name],
            'created_at' => $album->created_at?->toAtomString(),
            'updated_at' => $album->updated_at?->toAtomString(),
            'starts_on' => $album->starts_on?->format('Y-m-d'),
            'ends_on' => $album->ends_on?->format('Y-m-d'), 'location' => $album->location,
            'tags' => $album->tags->map(fn ($tag) => ['id' => $tag->id, 'label' => $tag->label])->values(),
            'people' => $album->relationLoaded('people')
                ? $album->people->map(fn ($person) => ['id' => $person->id, 'name' => $person->preferred_name])->values() : [],
            'cover' => $album->coverPhoto === null ? null : [
                'photo_id' => $album->coverPhoto->id,
                'media_upload_id' => $album->coverPhoto->media_upload_id,
                'focal_x' => (float) $album->cover_focal_x,
                'focal_y' => (float) $album->cover_focal_y,
            ],
            'cover_pending' => $album->current_cover_intent_id !== null,
            'photo_count' => $photoCount === null ? $album->albumPhotos->count() : (int) $photoCount,
            'event_id' => $album->event_id,
            'guest_participation' => $album->guest_participation->value,
            'event' => $album->event === null ? null : ['id' => $album->event->id,
                'name' => $album->event->name, 'starts_on' => $album->event->starts_on?->format('Y-m-d')],
            'photos' => $album->albumPhotos->map(fn ($link) => ['id' => $link->photo->id,
                'media_upload_id' => $link->photo->media_upload_id,
                'caption' => $link->photo->caption, 'visibility' => $link->photo->visibility->value,
                'client_filename' => $link->photo->mediaUpload->client_filename,
                'position' => $link->position])->values(),
            'grants' => $album->grants->map(fn ($grant) => ['membership_id' => $grant->family_space_membership_id,
                'name' => $grant->membership->user->name, 'can_view' => $grant->can_view, 'can_contribute' => $grant->can_contribute])->values(),
            'permissions' => ['can_manage' => Gate::allows('update', $album), 'can_contribute' => Gate::allows('contribute', $album)]];
    }

    /** @param Collection<int, Album> $albums */
    private function loadPresentation(Collection $albums): void
    {
        $albums->loadMissing(['creator:id,name', 'event:id,name,starts_on', 'tags:id,label', 'coverPhoto.mediaUpload',
            'albumPhotos' => fn ($query) => $query->whereHas('photo')->with('photo.mediaUpload'),
            'grants.membership.user:id,name']);
        if (Gate::allows('viewAny', Person::class)) {
            $albums->loadMissing('people:id,preferred_name');
        }
    }

    private function album(FamilySpace $space, string $id): Album
    {
        return Album::query()->where('family_space_id', $space->id)->findOrFail($id);
    }

    private function familySlug(): string
    {
        $familySpace = request()->route('familySpace');

        return $familySpace instanceof FamilySpace ? $familySpace->slug : (string) $familySpace;
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
