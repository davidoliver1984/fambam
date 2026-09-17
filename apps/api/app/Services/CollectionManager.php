<?php

namespace App\Services;

use App\Models\Collection;
use App\Models\CollectionPhoto;
use App\Models\FamilySpace;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CollectionManager
{
    /** @param array{name:string,description?:string|null} $data */
    public function create(FamilySpace $family, User $owner, array $data): Collection
    {
        return Collection::query()->create(['family_space_id' => $family->id,
            'owner_user_id' => $owner->id, 'name' => trim($data['name']),
            'description' => $data['description'] ?? null]);
    }

    /** @param array{name?:string,description?:string|null} $data */
    public function update(Collection $collection, array $data): Collection
    {
        $collection->update($data);

        return $collection;
    }

    public function delete(Collection $collection): void
    {
        DB::transaction(function () use ($collection): void {
            $locked = Collection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $locked->delete();
        });
    }

    public function add(Collection $collection, User $actor, string $photoId): void
    {
        DB::transaction(function () use ($collection, $actor, $photoId): void {
            $locked = Collection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $photo = Photo::query()->where('family_space_id', $locked->family_space_id)->findOrFail($photoId);
            Gate::forUser($actor)->authorize('view', $photo);
            $this->append($locked, [$photoId]);
        });
    }

    /** @param list<string> $photoIds */
    public function addVisible(Collection $collection, User $actor, array $photoIds): int
    {
        return DB::transaction(function () use ($collection, $actor, $photoIds): int {
            $locked = Collection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $visible = [];
            foreach (array_unique($photoIds) as $photoId) {
                $photo = Photo::query()->where('family_space_id', $locked->family_space_id)->find($photoId);
                if ($photo !== null && Gate::forUser($actor)->allows('view', $photo)) {
                    $visible[] = $photoId;
                }
            }

            return $this->append($locked, $visible);
        });
    }

    public function remove(Collection $collection, string $photoId): void
    {
        DB::transaction(function () use ($collection, $photoId): void {
            $locked = Collection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            CollectionPhoto::query()->where('collection_id', $locked->id)->where('photo_id', $photoId)->delete();
        });
    }

    /** @param list<string> $photoIds */
    public function reorder(Collection $collection, User $actor, array $photoIds): void
    {
        DB::transaction(function () use ($collection, $actor, $photoIds): void {
            $locked = Collection::query()->whereKey($collection->id)->lockForUpdate()->firstOrFail();
            $rows = CollectionPhoto::query()->with('photo')->where('collection_id', $locked->id)
                ->orderBy('position')->lockForUpdate()->get();
            $visible = $rows->filter(fn (CollectionPhoto $row): bool => $row->photo !== null
                && Gate::forUser($actor)->allows('view', $row->photo));
            $expected = $visible->pluck('photo_id')->all();
            if (count($expected) !== count($photoIds)
                || count(array_unique($photoIds)) !== count($photoIds)
                || array_diff($expected, $photoIds) !== []) {
                throw ValidationException::withMessages(['photo_ids' => ['Provide each currently visible Collection Photo exactly once.']]);
            }
            $positions = $visible->pluck('position')->all();
            $byPhoto = $visible->keyBy('photo_id');
            $temporary = ((int) $rows->max('position')) + count($rows) + 1;
            foreach ($visible as $row) {
                $row->update(['position' => $temporary++]);
            }
            foreach ($photoIds as $index => $photoId) {
                $byPhoto[$photoId]->update(['position' => $positions[$index]]);
            }
        });
    }

    /** @param list<string> $photoIds */
    private function append(Collection $collection, array $photoIds): int
    {
        $position = ((int) CollectionPhoto::query()->where('collection_id', $collection->id)->max('position')) + 1;
        $added = 0;
        foreach ($photoIds as $photoId) {
            if (CollectionPhoto::query()->where('collection_id', $collection->id)
                ->where('photo_id', $photoId)->exists()) {
                continue;
            }
            CollectionPhoto::query()->create(['family_space_id' => $collection->family_space_id,
                'collection_id' => $collection->id, 'photo_id' => $photoId, 'position' => $position++]);
            $added++;
        }

        return $added;
    }
}
