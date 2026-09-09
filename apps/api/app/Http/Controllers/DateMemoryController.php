<?php

namespace App\Http\Controllers;

use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\DateMemoryQuery;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DateMemoryController extends Controller
{
    public function __construct(private readonly DateMemoryQuery $memories) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('view', $familySpace);
        /** @var User $viewer */
        $viewer = $request->user();

        return response()->json([
            'data' => $this->memories->forDate($viewer, CarbonImmutable::now()),
        ]);
    }
}
