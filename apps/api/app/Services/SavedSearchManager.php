<?php

namespace App\Services;

use App\Models\Person;
use App\Models\SavedSearch;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\FamilyEventQuery;
use App\Queries\PersonQuery;
use App\Queries\PhotoQuery;
use App\Search\SearchQuery;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SavedSearchManager
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PersonQuery $people,
        private readonly FamilyEventQuery $events,
        private readonly AlbumQuery $albums,
        private readonly PhotoQuery $photos,
    ) {}

    /** @param array<string, mixed> $input */
    public function create(array $input, User $actor): SavedSearch
    {
        return DB::transaction(function () use ($input, $actor): SavedSearch {
            [$filters, $personIds] = $this->normalizeForWrite($input['filters'], $actor);
            $savedSearch = SavedSearch::query()->create([
                'family_space_id' => $this->tenantContext->familySpace()->id,
                'created_by' => $actor->id,
                'name' => trim((string) $input['name']),
                'filters' => $filters,
            ]);
            $this->syncPeople($savedSearch, $personIds);

            return $savedSearch->load('people:id,preferred_name');
        });
    }

    /** @param array<string, mixed> $input */
    public function update(SavedSearch $savedSearch, array $input, User $actor): SavedSearch
    {
        return DB::transaction(function () use ($savedSearch, $input, $actor): SavedSearch {
            $locked = SavedSearch::query()
                ->where('created_by', $actor->id)->lockForUpdate()->findOrFail($savedSearch->id);
            [$filters, $personIds] = $this->normalizeForWrite($input['filters'], $actor);
            $locked->update([
                'name' => trim((string) $input['name']),
                'filters' => $filters,
            ]);
            $this->syncPeople($locked, $personIds);

            return $locked->load('people:id,preferred_name');
        });
    }

    public function delete(SavedSearch $savedSearch, User $actor): void
    {
        SavedSearch::query()->where('created_by', $actor->id)
            ->where('id', $savedSearch->id)->delete();
    }

    public function effectiveQuery(
        SavedSearch $savedSearch,
        User $actor,
        int $limit,
        ?string $cursor,
    ): ?SearchQuery {
        $filters = $this->effectiveFilters($savedSearch, $actor);
        $query = new SearchQuery(
            $filters['q'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $filters['tag_id'] ?? null,
            $limit,
            $cursor,
            $filters['person_ids'],
            $filters['event_id'] ?? null,
            $filters['album_id'] ?? null,
            $filters['uploaded_by'] ?? null,
            $filters['visibility'] ?? null,
        );

        return $this->hasCriteria($query) ? $query : null;
    }

    /** @return array<string, mixed> */
    public function effectiveFilters(SavedSearch $savedSearch, User $actor): array
    {
        $stored = $savedSearch->filters;
        if (($stored['schema_version'] ?? null) !== 1) {
            return ['schema_version' => 1, 'person_ids' => []];
        }
        $personIds = [];
        if (Gate::forUser($actor)->allows('viewAny', Person::class)) {
            $requested = DB::table('saved_search_people')
                ->where('saved_search_id', $savedSearch->id)->pluck('person_id')->all();
            $personIds = $this->people->forCurrentFamilySpace()->setEagerLoads([])
                ->whereIn('id', $requested)->pluck('id')->all();
        }
        $eventId = $this->visibleEventId($stored['event_id'] ?? null, $actor);
        $albumId = $this->visibleAlbumId($stored['album_id'] ?? null, $actor);
        $tagId = $this->visibleTagId($stored['tag_id'] ?? null, $actor);
        $uploadedBy = $this->visibleUploaderId($stored['uploaded_by'] ?? null, $actor);

        return array_filter([
            'schema_version' => 1,
            'q' => $this->nullableString($stored['q'] ?? null),
            'date_from' => $this->nullableString($stored['date_from'] ?? null),
            'date_to' => $this->nullableString($stored['date_to'] ?? null),
            'tag_id' => $tagId,
            'event_id' => $eventId,
            'album_id' => $albumId,
            'uploaded_by' => $uploadedBy,
            'visibility' => $this->validVisibility($stored['visibility'] ?? null),
            'person_ids' => array_values($personIds),
        ], fn ($value): bool => $value !== null);
    }

    /** @param array<string, mixed> $input
     * @return array{array<string, mixed>, list<string>}
     */
    private function normalizeForWrite(array $input, User $actor): array
    {
        $personIds = array_values($input['person_ids'] ?? []);
        if ($personIds !== []) {
            if (Gate::forUser($actor)->denies('viewAny', Person::class)
                || $this->people->forCurrentFamilySpace()->setEagerLoads([])
                    ->whereIn('id', $personIds)->count() !== count($personIds)) {
                $this->invalidFilters();
            }
        }
        $eventId = $this->nullableString($input['event_id'] ?? null);
        if ($eventId !== null && $this->visibleEventId($eventId, $actor) === null) {
            $this->invalidFilters();
        }
        $tagId = $this->nullableString($input['tag_id'] ?? null);
        if ($tagId !== null && $this->visibleTagId($tagId, $actor) === null) {
            $this->invalidFilters();
        }
        $albumId = $this->nullableString($input['album_id'] ?? null);
        if ($albumId !== null && $this->visibleAlbumId($albumId, $actor) === null) {
            $this->invalidFilters();
        }
        $uploadedBy = isset($input['uploaded_by']) ? (int) $input['uploaded_by'] : null;
        if ($uploadedBy !== null && $this->visibleUploaderId($uploadedBy, $actor) === null) {
            $this->invalidFilters();
        }
        $filters = array_filter([
            'schema_version' => 1,
            'q' => $this->nullableString($input['q'] ?? null),
            'date_from' => $this->nullableString($input['date_from'] ?? null),
            'date_to' => $this->nullableString($input['date_to'] ?? null),
            'tag_id' => $tagId,
            'event_id' => $eventId,
            'album_id' => $albumId,
            'uploaded_by' => $uploadedBy,
            'visibility' => $this->validVisibility($input['visibility'] ?? null),
        ], fn ($value): bool => $value !== null);
        $probe = new SearchQuery(
            $filters['q'] ?? null,
            $filters['date_from'] ?? null,
            $filters['date_to'] ?? null,
            $filters['tag_id'] ?? null,
            1,
            null,
            $personIds,
            $filters['event_id'] ?? null,
            $filters['album_id'] ?? null,
            $filters['uploaded_by'] ?? null,
            $filters['visibility'] ?? null,
        );
        if (! $this->hasCriteria($probe)) {
            throw ValidationException::withMessages([
                'filters' => ['At least one search criterion is required.'],
            ]);
        }

        return [$filters, $personIds];
    }

    /** @param list<string> $personIds */
    private function syncPeople(SavedSearch $savedSearch, array $personIds): void
    {
        $familySpaceId = $this->tenantContext->familySpace()->id;
        $savedSearch->people()->sync(array_fill_keys(
            $personIds,
            ['family_space_id' => $familySpaceId],
        ));
    }

    private function visibleEventId(mixed $value, User $actor): ?string
    {
        $id = $this->nullableString($value);

        return $id !== null && $this->events->visibleTo($actor)->where('events.id', $id)->exists()
            ? $id
            : null;
    }

    private function visibleTagId(mixed $value, User $actor): ?string
    {
        $id = $this->nullableString($value);
        if ($id === null) {
            return null;
        }
        $visiblePhotoIds = $this->photos->visibleTo($actor)->setEagerLoads([])->select('photos.id');

        return DB::table('photo_tag')->where('tag_id', $id)
            ->whereIn('photo_id', $visiblePhotoIds)->exists() ? $id : null;
    }

    private function visibleAlbumId(mixed $value, User $actor): ?string
    {
        $id = $this->nullableString($value);

        return $id !== null && $this->albums->visibleTo($actor)->where('albums.id', $id)->exists()
            ? $id
            : null;
    }

    private function validVisibility(mixed $value): ?string
    {
        return is_string($value) && in_array($value, ['family_space', 'selected', 'private'], true)
            ? $value
            : null;
    }

    private function visibleUploaderId(mixed $value, User $actor): ?int
    {
        if (! is_int($value) || $value < 1) {
            return null;
        }

        return $this->photos->visibleTo($actor)->setEagerLoads([])
            ->whereHas('mediaUpload', fn ($uploads) => $uploads->where('user_id', $value))
            ->exists() ? $value : null;
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function hasCriteria(SearchQuery $query): bool
    {
        return $query->term !== null || $query->dateFrom !== null || $query->dateTo !== null
            || $query->tagId !== null || $query->personIds !== [] || $query->eventId !== null
            || $query->albumId !== null || $query->uploadedBy !== null || $query->visibility !== null;
    }

    private function invalidFilters(): never
    {
        throw ValidationException::withMessages([
            'filters' => ['One or more search filters are unavailable.'],
        ]);
    }
}
