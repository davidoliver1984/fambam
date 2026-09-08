<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSavedSearchRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\SavedSearch;
use App\Models\User;
use App\Queries\SavedSearchQuery;
use App\Search\SearchExecutor;
use App\Services\SavedSearchManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SavedSearchController extends Controller
{
    public function __construct(
        private readonly SavedSearchQuery $savedSearches,
        private readonly SavedSearchManager $manager,
        private readonly SearchExecutor $executor,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->savedSearches->listFor($actor)
            ->map(fn (SavedSearch $savedSearch): array => $this->payload($savedSearch, $actor))]);
    }

    public function store(FamilySpace $familySpace, StoreSavedSearchRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->manager->create($request->validated(), $actor), $actor),
        ], 201);
    }

    public function update(
        FamilySpace $familySpace,
        string $savedSearch,
        StoreSavedSearchRequest $request,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->savedSearches->findFor($actor, $savedSearch);

        return response()->json([
            'data' => $this->payload($this->manager->update($target, $request->validated(), $actor), $actor),
        ]);
    }

    public function destroy(FamilySpace $familySpace, string $savedSearch, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->delete($this->savedSearches->findFor($actor, $savedSearch), $actor);

        return response()->json(null, 204);
    }

    public function run(FamilySpace $familySpace, string $savedSearch, Request $request): JsonResponse
    {
        $values = $request->validate([
            'group' => ['nullable', 'string', 'in:people,photos,albums,events,stories'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.config('search.maximum_page_size')],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        $saved = $this->savedSearches->findFor($actor, $savedSearch);
        $group = $values['group'] ?? null;
        if ($group === 'people') {
            Gate::forUser($actor)->authorize('viewAny', Person::class);
        }
        $query = $this->manager->effectiveQuery(
            $saved,
            $actor,
            (int) ($values['limit'] ?? config('search.default_page_size')),
            isset($values['cursor']) ? (string) $values['cursor'] : null,
        );

        return response()->json(['data' => $query === null
            ? $this->emptyResults($group, $actor)
            : $this->executor->run($query, $actor, $group)]);
    }

    /** @return array<string, mixed> */
    private function payload(SavedSearch $savedSearch, User $actor): array
    {
        $filters = $this->manager->effectiveFilters($savedSearch, $actor);
        $personIds = $filters['person_ids'];

        return [
            'id' => $savedSearch->id,
            'name' => $savedSearch->name,
            'filters' => $filters,
            'people' => $savedSearch->people->whereIn('id', $personIds)
                ->map(fn (Person $person): array => [
                    'id' => $person->id,
                    'preferred_name' => $person->preferred_name,
                ])->values(),
        ];
    }

    /** @return array<string, array{items: array<never>, next_cursor: null}> */
    private function emptyResults(?string $group, User $actor): array
    {
        $groups = $group === null ? ['photos', 'albums', 'events', 'stories'] : [$group];
        if ($group === null && Gate::forUser($actor)->allows('viewAny', Person::class)) {
            array_unshift($groups, 'people');
        }

        return array_fill_keys($groups, ['items' => [], 'next_cursor' => null]);
    }
}
