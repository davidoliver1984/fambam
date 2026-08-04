<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\ChangeMembershipRoleRequest;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Queries\FamilySpaceMembershipQuery;
use App\Services\FamilySpaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilySpaceMembershipController extends Controller
{
    public function __construct(
        private readonly FamilySpaceMembershipQuery $memberships,
        private readonly FamilySpaceManager $familySpaces,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('manageMembers', $familySpace);

        return response()->json([
            'data' => $this->memberships
                ->listForFamilySpace($familySpace)
                ->map($this->payload(...)),
        ]);
    }

    public function update(
        FamilySpace $familySpace,
        string $membership,
        ChangeMembershipRoleRequest $request,
    ): JsonResponse {
        Gate::authorize('manageMembers', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->memberships->findForFamilySpace($familySpace, $membership);
        $updated = $this->familySpaces->changeRole(
            $actor,
            $target,
            FamilySpaceRole::from($request->validated('role')),
            $request,
        );

        return response()->json(['data' => $this->payload($updated->load('user:id,name,email'))]);
    }

    public function destroy(
        FamilySpace $familySpace,
        string $membership,
        Request $request,
    ): JsonResponse {
        Gate::authorize('manageMembers', $familySpace);
        /** @var User $actor */
        $actor = $request->user();
        $target = $this->memberships->findForFamilySpace($familySpace, $membership);
        $removed = $this->familySpaces->remove($actor, $target, $request);

        return response()->json(['data' => $this->payload($removed->load('user:id,name,email'))]);
    }

    /** @return array{id: string, user: array{id: int, name: string, email: string}, role: string, state: string, removed_at: ?string} */
    private function payload(FamilySpaceMembership $membership): array
    {
        return [
            'id' => $membership->id,
            'user' => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ],
            'role' => $membership->role->value,
            'state' => $membership->state->value,
            'removed_at' => $membership->removed_at?->toAtomString(),
        ];
    }
}
