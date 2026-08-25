<?php

namespace App\Http\Controllers;

use App\Http\Requests\InitiateMediaUploadRequest;
use App\Http\Requests\StoreAlbumGrantRequest;
use App\Http\Requests\StoreAlbumPhotoRequest;
use App\Http\Requests\StoreAlbumRequest;
use App\Http\Requests\UpdateAlbumRequest;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Queries\AlbumQuery;
use App\Services\AlbumManager;
use App\Services\MediaUploadManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AlbumController extends Controller
{
    public function __construct(private readonly AlbumQuery $albums, private readonly AlbumManager $manager, private readonly MediaUploadManager $uploads) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Album::class);

        return response()->json(['data' => $this->albums->listVisibleTo($request->user())->map($this->payload(...))]);
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

    public function initiateUpload(FamilySpace $familySpace, string $album, InitiateMediaUploadRequest $request): JsonResponse
    {
        $target = $this->album($familySpace, $album);
        Gate::authorize('contribute', $target);
        $key = trim((string) $request->header('Idempotency-Key'));
        abort_if($key === '' || strlen($key) > 100, 422, 'A valid Idempotency-Key header is required.');
        $result = $this->uploads->initiate($familySpace, $request->user(), $key, $request->validated(), $request, $target->id);

        return response()->json(['data' => ['id' => $result->upload->id, 'state' => $result->upload->state->value,
            'target_album_id' => $target->id, 'upload_authorization' => $result->authorization === null ? null : [
                'url' => $result->authorization->url, 'method' => 'PUT', 'headers' => $result->authorization->headers,
                'expires_at' => $result->authorization->expiresAt->toAtomString()]]], $result->created ? 201 : 200);
    }

    /** @return array<string, mixed> */
    private function payload(Album $album): array
    {
        $album->load(['creator:id,name', 'event:id,name,starts_on',
            'albumPhotos' => fn ($query) => $query->whereHas('photo')->with('photo.mediaUpload'),
            'grants.membership.user:id,name']);

        return ['id' => $album->id, 'name' => $album->name, 'description' => $album->description,
            'visibility' => $album->visibility->value, 'created_by' => $album->created_by,
            'event_id' => $album->event_id,
            'event' => $album->event === null ? null : ['id' => $album->event->id,
                'name' => $album->event->name, 'starts_on' => $album->event->starts_on?->format('Y-m-d')],
            'photos' => $album->albumPhotos->map(fn ($link) => ['id' => $link->photo->id,
                'caption' => $link->photo->caption, 'visibility' => $link->photo->visibility->value,
                'client_filename' => $link->photo->mediaUpload->client_filename,
                'position' => $link->position])->values(),
            'grants' => $album->grants->map(fn ($grant) => ['membership_id' => $grant->family_space_membership_id,
                'name' => $grant->membership->user->name, 'can_view' => $grant->can_view, 'can_contribute' => $grant->can_contribute])->values(),
            'permissions' => ['can_manage' => Gate::allows('update', $album), 'can_contribute' => Gate::allows('contribute', $album)]];
    }

    private function album(FamilySpace $space, string $id): Album
    {
        return Album::query()->where('family_space_id', $space->id)->findOrFail($id);
    }
}
