<?php

namespace App\Search;

use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class SearchExecutor
{
    public function __construct(private readonly SearchService $search) {}

    /** @return array<string, array{items: list<array<string, mixed>>, next_cursor: ?string}> */
    public function run(SearchQuery $query, User $actor, ?string $group): array
    {
        return match ($group) {
            'people' => ['people' => $this->authorizedPeople($query, $actor)->toArray()],
            'photos' => ['photos' => $this->search->photos($query, $actor)->toArray()],
            'albums' => ['albums' => $this->search->albums($query, $actor)->toArray()],
            'events' => ['events' => $this->search->events($query, $actor)->toArray()],
            'stories' => ['stories' => $this->search->stories($query, $actor)->toArray()],
            default => $this->allGroups($query, $actor),
        };
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
        if (Gate::forUser($actor)->allows('viewAny', Person::class)) {
            $groups = ['people' => $this->search->people($query, $actor)->toArray(), ...$groups];
        }

        return $groups;
    }

    private function authorizedPeople(SearchQuery $query, User $actor): SearchPage
    {
        Gate::forUser($actor)->authorize('viewAny', Person::class);

        return $this->search->people($query, $actor);
    }
}
