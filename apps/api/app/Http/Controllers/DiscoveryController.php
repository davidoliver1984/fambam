<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\User;
use App\Queries\AlbumQuery;
use App\Queries\FamilyEventQuery;
use App\Queries\PersonQuery;
use App\Queries\PhotoQuery;
use App\Search\DiscoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DiscoveryController extends Controller
{
    public function __construct(
        private readonly DiscoveryService $discovery,
        private readonly PersonQuery $people,
        private readonly PhotoQuery $photos,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
    ) {}

    public function show(FamilySpace $familySpace, string $type, string $id, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $data = match ($type) {
            'people' => $this->fromPerson($id, $actor),
            'photos' => $this->discovery->fromPhoto($this->photos->findVisibleTo($actor, $id), $actor),
            'albums' => $this->discovery->fromAlbum($this->albums->findVisibleTo($actor, $id), $actor),
            'events' => $this->discovery->fromEvent($this->events->findVisibleTo($actor, $id), $actor),
            default => abort(404),
        };

        return response()->json(['data' => ['source' => ['type' => $type, 'id' => $id], 'related' => $data]]);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function fromPerson(string $id, User $actor): array
    {
        Gate::authorize('viewAny', Person::class);
        $person = $this->people->findForCurrentFamilySpace($id);
        Gate::authorize('view', $person);

        return $this->discovery->fromPerson($person, $actor);
    }
}
