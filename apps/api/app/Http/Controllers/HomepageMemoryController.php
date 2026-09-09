<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\HomepageMemoryQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class HomepageMemoryController extends Controller
{
    public function __construct(private readonly HomepageMemoryQuery $memories) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('view', $familySpace);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            'data' => $this->memories->forViewer($viewer),
        ]);
    }
}
