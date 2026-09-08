<?php

namespace App\Http\Controllers;

use App\Http\Requests\SearchArchiveRequest;
use App\Models\FamilySpace;
use App\Models\User;
use App\Search\SearchQuery;
use App\Search\SearchService;
use Illuminate\Http\JsonResponse;

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
        );
        /** @var User $actor */
        $actor = $request->user();
        $group = $values['group'] ?? null;
        $data = match ($group) {
            'photos' => ['photos' => $this->search->photos($query, $actor)->toArray()],
            'albums' => ['albums' => $this->search->albums($query, $actor)->toArray()],
            'stories' => ['stories' => $this->search->stories($query, $actor)->toArray()],
            default => [
                'photos' => $this->search->photos($query, $actor)->toArray(),
                'albums' => $this->search->albums($query, $actor)->toArray(),
                'stories' => $this->search->stories($query, $actor)->toArray(),
            ],
        };

        return response()->json(['data' => $data]);
    }
}
