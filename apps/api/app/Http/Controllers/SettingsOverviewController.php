<?php

namespace App\Http\Controllers;

use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\Photo;
use App\Models\User;
use App\Queries\SettingsOverviewQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class SettingsOverviewController extends Controller
{
    public function __construct(private readonly SettingsOverviewQuery $overview) {}

    public function show(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('view', $familySpace);
        Gate::authorize('viewAny', Person::class);
        Gate::authorize('viewAny', Photo::class);
        Gate::authorize('viewAny', Album::class);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['data' => $this->overview->forViewer($viewer)]);
    }
}
