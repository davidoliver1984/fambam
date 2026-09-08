<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchArchiveRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use App\Search\SearchPage;
use App\Search\SearchQuery;
use App\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function __construct(private readonly SearchService $search) {}

    public function index(FamilySpace $familySpace, SearchArchiveRequest $request): JsonResponse
    {
        $values = $request->validated();
        $query = new SearchQuery(
            isset($values['q']) ? (string) $values['q'] : null,
            isset($values['date_from']) ? (string) $values['date_from'] : null,
            isset($values['date_to']) ? (string) $values['date_to'] : null,
            isset($values['tag_id']) ? (string) $values['tag_id'] : null,
            (int) ($values['limit'] ?? config('search.default_page_size')),
            isset($values['cursor']) ? (string) $values['cursor'] : null,
            array_values($values['person_ids'] ?? []),
            isset($values['event_id']) ? (string) $values['event_id'] : null,
        );
        /** @var User $actor */
        $actor = $request->user();
        $group = $values['group'] ?? null;
        $data = match ($group) {
            'people' => ['people' => $this->authorizedPeople($query, $actor)->toArray()],
            'photos' => ['photos' => $this->search->photos($query, $actor)->toArray()],
            'albums' => ['albums' => $this->search->albums($query, $actor)->toArray()],
            'events' => ['events' => $this->search->events($query, $actor)->toArray()],
            'stories' => ['stories' => $this->search->stories($query, $actor)->toArray()],
            default => $this->allGroups($query, $actor),
        };

        return response()->json(['data' => $data]);
    }

    public function suggestions(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $values = $request->validate([
            'type' => ['required', 'string', 'in:people,albums,events,tags'],
            'prefix' => ['required', 'string', 'min:1', 'max:80'],
        ]);
        /** @var User $actor */
        $actor = $request->user();
        if ($values['type'] === 'people') {
            Gate::authorize('viewAny', Person::class);
        }

        return response()->json(['data' => $this->search->suggest(
            $values['type'],
            $values['prefix'],
            $actor,
        )]);
    }

    /** @return array<string, array{items: list<array<string, mixed>>, next_cursor: ?string}> */
    private function allGroups(SearchQuery $query, User $actor): array
    {
        $groups = [
            'photos' => $this->search->photos($query, $actor)->toArray(),
            'albums' => $this->search->albums($query, $actor)->toArray(),
            'events' => $this->search->events($query, $actor)->toArray(),
            'stories' => $this->search->stories($query, $actor)->toArray(),
        ];
        if (Gate::allows('viewAny', Person::class)) {
            $groups = ['people' => $this->search->people($query, $actor)->toArray(), ...$groups];
        }

        return $groups;
    }

    private function authorizedPeople(SearchQuery $query, User $actor): SearchPage
    {
        Gate::authorize('viewAny', Person::class);

        return $this->search->people($query, $actor);
    }
}
