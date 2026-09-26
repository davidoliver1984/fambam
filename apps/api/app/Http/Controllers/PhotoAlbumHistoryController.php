<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\PhotoAlbumHistoryQuery;
use App\Queries\PhotoQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PhotoAlbumHistoryController extends Controller
{
    public function __construct(
        private readonly PhotoQuery $photos,
        private readonly PhotoAlbumHistoryQuery $history,
    ) {}

    public function index(FamilySpace $familySpace, string $photo, Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $target = $this->photos->findVisibleTo($viewer, $photo);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->history->forPhoto($target, $viewer)]);
    }
}
