<?php

namespace App\Http\Controllers;

use App\Enums\FamilySpaceRole;
use App\Http\Requests\ChangeMembershipRoleRequest;
use App\Models\FamilySpace;
use App\Models\FamilySpaceMembership;
use App\Models\User;
use App\Queries\FamilySpaceMembershipQuery;
use App\Services\ActorPresentationService;
use App\Services\FamilySpaceManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class FamilySpaceMembershipController extends Controller
{
    public function __construct(
        private readonly FamilySpaceMembershipQuery $memberships,
        private readonly FamilySpaceManager $familySpaces,
        private readonly ActorPresentationService $presentations,
    ) {}

    public function index(FamilySpace $familySpace, Request $request): JsonResponse
    {
        Gate::authorize('manageMembers', $familySpace);
        /** @var User $viewer */
        $viewer = $request->user();
        $memberships = $this->memberships->listForFamilySpace($familySpace);
        $presentations = $this->presentations->forUsers($memberships->pluck('user'), $viewer);

        return response()->json([
            'data' => $memberships->map(
                fn (FamilySpaceMembership $membership): array => $this->payload(
                    $membership,
                    $viewer,
                    $presentations,
                ),
            ),
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

        $updated->load('user:id,name,email');
        $presentations = $this->presentations->forUsers(collect([$updated->user]), $actor);

        return response()->json(['data' => $this->payload($updated, $actor, $presentations)]);
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

        $removed->load('user:id,name,email');
        $presentations = $this->presentations->forUsers(collect([$removed->user]), $actor);

        return response()->json(['data' => $this->payload($removed, $actor, $presentations)]);
    }

    /**
     * @param  array<int, array{display_name: string, person_id: string|null, initials: string, portrait_thumbnail_url: string|null}>  $presentations
     * @return array<string, mixed>
     */
    private function payload(FamilySpaceMembership $membership, User $viewer, array $presentations): array
    {
        $presentation = $presentations[$membership->user_id] ?? null;

        return [
            'id' => $membership->id,
            'user' => [
                'id' => $membership->user->id,
                'name' => $membership->user->name,
                'email' => $membership->user->email,
            ],
            'role' => $membership->role->value,
            'state' => $membership->state->value,
            'joined_at' => $membership->joined_at->toAtomString(),
            'removed_at' => $membership->removed_at?->toAtomString(),
            'linked_person' => ($presentation['person_id'] ?? null) === null ? null : [
                'id' => $presentation['person_id'],
                'display_name' => $presentation['display_name'],
                'portrait_thumbnail_url' => $presentation['portrait_thumbnail_url'],
            ],
            'is_current_user' => $membership->user_id === $viewer->id,
        ];
    }
}
