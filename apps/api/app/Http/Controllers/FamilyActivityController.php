<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\FamilyActivityQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilyActivityController extends Controller
{
    public function __construct(private readonly FamilyActivityQuery $activities) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('view', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->activities->recent($actor)]);
    }
}
