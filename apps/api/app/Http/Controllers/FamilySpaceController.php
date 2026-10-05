<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceStatus;
use App\Http\Requests\CreateFamilySpaceRequest;
use App\Http\Requests\TransferFamilyOwnershipRequest;
use App\Http\Requests\UpdateFamilySpaceRequest;
use App\Models\FamilySpace;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Queries\FamilySpaceMembershipQuery;
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
        private readonly FamilySpaceMembershipQuery $memberships,
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

    public function update(FamilySpace $familySpace, UpdateFamilySpaceRequest $request): JsonResponse
    {
        Gate::authorize('update', $familySpace);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->familySpaces->updateSettings(
                $actor,
                $familySpace,
                $request->validated(),
                $request,
            )),
        ]);
    }

    public function transferOwnership(
        FamilySpace $familySpace,
        TransferFamilyOwnershipRequest $request,
    ): JsonResponse {
        Gate::authorize('transferOwnership', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->memberships->findForFamilySpace(
            $familySpace,
            $request->validated('membership_id'),
        );
        $this->familySpaces->transferOwnership($actor, $familySpace, $target, $request);

        return response()->json(['data' => $this->payload($familySpace->refresh())]);
    }

    public function leave(FamilySpace $familySpace, Request $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $this->familySpaces->leave($actor, $familySpace, $request);

        return response()->json(null, 204);
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

    /** @return array<string, mixed> */
    private function payload(FamilySpace $familySpace): array
    {
        $membership = $this->tenantContext->isEstablished()
            && $this->tenantContext->familySpace()->is($familySpace)
                ? $this->tenantContext->membership()->refresh()
                : $familySpace->memberships->sole();
        $canViewDeletion = $membership->role->canManageMembers();
        $payload = [
            'id' => $familySpace->id,
            'slug' => $familySpace->slug,
            'name' => $familySpace->name,
            'description' => $familySpace->description,
            'default_visibility' => $familySpace->default_visibility->value,
            'status' => $canViewDeletion
                ? $familySpace->status->value
                : FamilySpaceStatus::Active->value,
            'role' => $membership->role->value,
            'permissions' => [
                'can_update_family_settings' => $membership->role->canManageMembers(),
                'can_manage_members' => $membership->role->canManageMembers(),
                'can_manage_invitations' => $membership->role->canManageMembers(),
                'can_transfer_ownership' => $membership->role->value === 'owner',
                'can_leave_family' => $membership->role->value !== 'owner',
            ],
        ];

        if ($this->tenantContext->isEstablished()
            && $this->tenantContext->familySpace()->is($familySpace)) {
            $payload['current_user_person_id'] = PersonAccountLink::query()
                ->where('family_space_id', $familySpace->id)
                ->where('user_id', $membership->user_id)
                ->whereHas('person')
                ->value('person_id');
        }

        if ($canViewDeletion) {
            $payload['deletion'] = [
                'requested_at' => $familySpace->deletion_requested_at?->toAtomString(),
                'scheduled_at' => $familySpace->scheduled_deletion_at?->toAtomString(),
            ];
        }

        return $payload;
    }
}
