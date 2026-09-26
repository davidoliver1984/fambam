<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\HomeQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class HomeController extends Controller
{
    public function __construct(private readonly HomeQuery $home) {}

    public function show(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('view', $familySpace);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json(['data' => $this->home->forViewer($viewer, CarbonImmutable::now())]);
    }
}
