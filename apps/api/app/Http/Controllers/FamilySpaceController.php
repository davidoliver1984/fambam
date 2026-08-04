<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateFamilySpaceRequest;
use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\FamilySpaceQuery;
use App\Services\FamilySpaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilySpaceController extends Controller
{
    public function __construct(
        private readonly FamilySpaceManager $familySpaces,
        private readonly FamilySpaceQuery $familySpaceQuery,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $familySpaces = $this->familySpaceQuery
            ->listAccessibleTo($user)
            ->map(fn (FamilySpace $familySpace): array => $this->payload($familySpace));

        return response()->json(['data' => $familySpaces]);
    }

    public function show(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('view', $familySpace);

        return response()->json(['data' => $this->payload($familySpace)]);
    }

    public function store(CreateFamilySpaceRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $familySpace = $this->familySpaces->create(
            $actor,
            $request->validated('name'),
            $request->validated('slug'),
            $request,
        );

        return response()->json(['data' => $this->payload($familySpace->load('memberships'))], 201);
    }

    /** @return array{id: string, slug: string, name: string, status: string, role: string} */
    private function payload(FamilySpace $familySpace): array
    {
        $membership = $familySpace->memberships->sole();

        return [
            'id' => $familySpace->id,
            'slug' => $familySpace->slug,
            'name' => $familySpace->name,
            'status' => $familySpace->status->value,
            'role' => $membership->role->value,
        ];
    }
}
