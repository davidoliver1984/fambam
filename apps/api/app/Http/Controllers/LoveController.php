<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\FamilyEvent;
use App\Models\FamilySpace;
use App\Models\Story;
use App\Queries\AlbumQuery;
use App\Queries\FamilyEventQuery;
use App\Queries\PhotoQuery;
use App\Services\LoveManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class LoveController extends Controller
{
    public function __construct(
        private readonly LoveManager $manager,
        private readonly AlbumQuery $albums,
        private readonly FamilyEventQuery $events,
        private readonly PhotoQuery $photos,
    ) {}

    public function album(FamilySpace $familySpace, string $album, Request $request): JsonResponse
    {
        return $this->show($this->albumTarget($album, $request), $request);
    }

    public function loveAlbum(FamilySpace $familySpace, string $album, Request $request): JsonResponse
    {
        return $this->save($this->albumTarget($album, $request), $request);
    }

    public function unloveAlbum(FamilySpace $familySpace, string $album, Request $request): JsonResponse
    {
        return $this->remove($this->albumTarget($album, $request), $request);
    }

    public function event(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        return $this->show($this->eventTarget($event, $request), $request);
    }

    public function loveEvent(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        return $this->save($this->eventTarget($event, $request), $request);
    }

    public function unloveEvent(FamilySpace $familySpace, string $event, Request $request): JsonResponse
    {
        return $this->remove($this->eventTarget($event, $request), $request);
    }

    public function story(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        return $this->show($this->storyTarget($story), $request);
    }

    public function loveStory(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        return $this->save($this->storyTarget($story), $request);
    }

    public function unloveStory(FamilySpace $familySpace, string $story, Request $request): JsonResponse
    {
        return $this->remove($this->storyTarget($story), $request);
    }

    public function photo(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        $target = $this->photos->findVisibleTo($request->user(), $photo);
        Gate::authorize('view', $target);
        $album = $this->albums->findVisibleTo($request->user(), $request->string('album_id')->toString());
        abort_unless($album->photos()->whereKey($target->id)->exists(), 404);

        return response()->json(['data' => $this->manager->summary($target, $request->user(), $album)]);
    }

    private function albumTarget(string $id, Request $request): Album
    {
        $album = $this->albums->findVisibleTo($request->user(), $id);
        Gate::authorize('view', $album);

        return $album;
    }

    private function eventTarget(string $id, Request $request): FamilyEvent
    {
        $event = $this->events->findVisibleTo($request->user(), $id);
        Gate::authorize('view', $event);

        return $event;
    }

    private function storyTarget(string $id): Story
    {
        $story = Story::query()->findOrFail($id);
        Gate::authorize('view', $story);

        return $story;
    }

    private function show(Album|FamilyEvent|Story $target, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->manager->summary($target, $request->user())]);
    }

    private function save(Album|FamilyEvent|Story $target, Request $request): JsonResponse
    {
        $this->manager->save($target, $request->user(), $request);

        return $this->show($target, $request);
    }

    private function remove(Album|FamilyEvent|Story $target, Request $request): JsonResponse
    {
        $this->manager->remove($target, $request->user());

        return response()->json(null, 204);
    }
}
