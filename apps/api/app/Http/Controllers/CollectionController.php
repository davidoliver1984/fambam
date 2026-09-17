<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\Collection;
use App\Models\CollectionPhoto;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\User;
use App\Services\CollectionManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CollectionController extends Controller
{
    public function __construct(private readonly CollectionManager $manager) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Collection::class);

        return response()->json(['data' => Collection::query()->where('family_space_id', $familySpace->id)
            ->where('owner_user_id', $request->user()->id)->orderBy('name')->orderBy('id')->get()
            ->map(fn (Collection $collection): array => $this->payload($collection))]);
    }

    public function store(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('create', Collection::class);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:5000']]);

        return response()->json(['data' => $this->payload(
            $this->manager->create($familySpace, $request->user(), $data), true,
        )], 201);
    }

    public function show(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payload($this->owned($familySpace, $collection, $request), true)]);
    }

    public function update(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        $target = $this->owned($familySpace, $collection, $request);
        $data = $request->validate(['name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000']]);

        return response()->json(['data' => $this->payload($this->manager->update($target, $data), true)]);
    }

    public function destroy(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        $target = $this->owned($familySpace, $collection, $request);
        $this->manager->delete($target);

        return response()->json(null, 204);
    }

    public function addPhoto(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        $target = $this->owned($familySpace, $collection, $request);
        $photoId = $request->validate(['photo_id' => ['required', 'ulid']])['photo_id'];
        $this->manager->add($target, $request->user(), $photoId);

        return response()->json(['data' => $this->payload($target, true)], 201);
    }

    public function removePhoto(FamilySpace $familySpace, string $collection, string $photo, Request $request): JsonResponse
    {
        $this->manager->remove($this->owned($familySpace, $collection, $request), $photo);

        return response()->json(null, 204);
    }

    public function reorder(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        $target = $this->owned($familySpace, $collection, $request);
        $ids = $request->validate(['photo_ids' => ['required', 'array', 'max:1000'],
            'photo_ids.*' => ['ulid', 'distinct']])['photo_ids'];
        $this->manager->reorder($target, $request->user(), $ids);

        return response()->json(['data' => $this->payload($target, true)]);
    }

    public function populate(FamilySpace $familySpace, string $collection, Request $request): JsonResponse
    {
        $target = $this->owned($familySpace, $collection, $request);
        $data = $request->validate(['source_type' => ['required', Rule::in(['album', 'event'])],
            'source_id' => ['required', 'ulid']]);
        if ($data['source_type'] === 'album') {
            $album = Album::query()->where('family_space_id', $familySpace->id)->findOrFail($data['source_id']);
            Gate::authorize('view', $album);
            $ids = DB::table('album_photos')->where('album_id', $album->id)
                ->orderBy('position')->pluck('photo_id')->all();
        } else {
            $event = FamilyEvent::query()->where('family_space_id', $familySpace->id)->findOrFail($data['source_id']);
            Gate::authorize('view', $event);
            $ids = Photo::query()->where('family_space_id', $familySpace->id)
                ->where(fn ($query) => $query->where('primary_event_id', $event->id)
                    ->orWhereHas('albums', fn ($albums) => $albums->where('albums.event_id', $event->id)))
                ->orderBy('created_at')->orderBy('id')->pluck('id')->all();
        }

        $added = $this->manager->addVisible($target, $request->user(), $ids);

        return response()->json(['data' => $this->payload($target, true), 'added' => $added]);
    }

    private function owned(FamilySpace $familySpace, string $id, Request $request): Collection
    {
        /** @var User $actor */
        $actor = $request->user();
        $collection = Collection::query()->where('family_space_id', $familySpace->id)
            ->where('owner_user_id', $actor->id)->findOrFail($id);
        Gate::authorize('view', $collection);

        return $collection;
    }

    /** @return array<string, mixed> */
    private function payload(Collection $collection, bool $detailed = false): array
    {
        $data = ['id' => $collection->id, 'name' => $collection->name,
            'description' => $collection->description,
            'created_at' => $collection->created_at?->toIso8601String()];
        if (! $detailed) {
            return $data;
        }
        $data['photos'] = CollectionPhoto::query()->where('collection_id', $collection->id)
            ->with('photo')->orderBy('position')->get()
            ->filter(fn (CollectionPhoto $row): bool => $row->photo !== null && Gate::allows('view', $row->photo))
            ->map(fn (CollectionPhoto $row): array => ['id' => $row->photo->id,
                'caption' => $row->photo->caption, 'media_upload_id' => $row->photo->media_upload_id,
                'position' => $row->position])->values();

        return $data;
    }
}
