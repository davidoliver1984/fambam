<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceStatus;
use App\Http\Requests\CreateFamilySpaceRequest;
use App\Models\FamilySpace;
use App\Models\User;
use App\Queries\FamilySpaceQuery;
use App\Services\FamilySpaceDeletionManager;
use App\Services\FamilySpaceManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilySpaceController extends Controller
{
    public function __construct(
        private readonly FamilySpaceManager $familySpaces,
        private readonly FamilySpaceDeletionManager $deletions,
        private readonly FamilySpaceQuery $familySpaceQuery,
        private readonly TenantContext $tenantContext,
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

    public function requestDeletion(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('requestDeletion', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->deletions->request($familySpace, $actor, $request)),
        ]);
    }

    public function cancelDeletion(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('cancelDeletion', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->deletions->cancel($familySpace, $actor, $request)),
        ]);
    }

    /** @return array{id: string, slug: string, name: string, status: string, role: string, deletion?: array{requested_at: ?string, scheduled_at: ?string}} */
    private function payload(FamilySpace $familySpace): array
    {
        $membership = $this->tenantContext->isEstablished()
            && $this->tenantContext->familySpace()->is($familySpace)
                ? $this->tenantContext->membership()
                : $familySpace->memberships->sole();
        $canViewDeletion = $membership->role->canManageMembers();
        $payload = [
            'id' => $familySpace->id,
            'slug' => $familySpace->slug,
            'name' => $familySpace->name,
            'status' => $canViewDeletion
                ? $familySpace->status->value
                : FamilySpaceStatus::Active->value,
            'role' => $membership->role->value,
        ];

        if ($canViewDeletion) {
            $payload['deletion'] = [
                'requested_at' => $familySpace->deletion_requested_at?->toAtomString(),
                'scheduled_at' => $familySpace->scheduled_deletion_at?->toAtomString(),
            ];
        }

        return $payload;
    }
}
