<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchArchiveRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use App\Search\SearchExecutor;
use App\Search\SearchQuery;
use App\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
        private readonly SearchExecutor $executor,
    ) {}

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
            isset($values['album_id']) ? (string) $values['album_id'] : null,
            isset($values['uploaded_by']) ? (int) $values['uploaded_by'] : null,
            isset($values['visibility']) ? (string) $values['visibility'] : null,
        );
        /** @var User $actor */
        $actor = $request->user();
        $group = $values['group'] ?? null;
        $data = $this->executor->run($query, $actor, $group);

        return response()->json(['data' => $data]);
    }

    public function suggestions(FamilySpace $familySpace, Request $request): JsonResponse
    {
        $values = $request->validate([
            'type' => ['required', 'string', 'in:people,albums,events,tags,uploaders'],
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
}
