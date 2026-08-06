<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignPersonAccountRequest;
use App\Models\FamilySpace;
use App\Models\PersonAccountClaim;
use App\Models\PersonAccountLink;
use App\Models\User;
use App\Queries\PersonAccountLinkQuery;
use App\Queries\PersonQuery;
use App\Services\PersonAccountLinkManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonAccountLinkController extends Controller
{
    public function __construct(
        private readonly PersonQuery $people,
        private readonly PersonAccountLinkQuery $links,
        private readonly PersonAccountLinkManager $manager,
    ) {}

    public function claims(FamilySpace $familySpace, string $person): JsonResponse
    {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageAccountLink', $target);

        return response()->json([
            'data' => $this->links->pendingClaims($target)->map($this->claimPayload(...)),
        ]);
    }

    public function proposeClaim(FamilySpace $familySpace, string $person, Request $request): JsonResponse
    {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('proposeAccountLink', $target);
        /** @var User $actor */
        $actor = $request->user();

        $claim = $this->manager->propose($target, $actor, $request)->load('user:id,name');

        return response()->json(['data' => $this->claimPayload($claim)], 201);
    }

    public function approveClaim(
        FamilySpace $familySpace,
        string $person,
        string $claim,
        Request $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageAccountLink', $target);
        $targetClaim = $this->links->findClaim($target, $claim);
        /** @var User $actor */
        $actor = $request->user();
        $link = $this->manager->approve($target, $targetClaim, $actor, $request)->load('user:id,name');

        return response()->json(['data' => $this->linkPayload($link, $actor)]);
    }

    public function rejectClaim(
        FamilySpace $familySpace,
        string $person,
        string $claim,
        Request $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageAccountLink', $target);
        $targetClaim = $this->links->findClaim($target, $claim);
        /** @var User $actor */
        $actor = $request->user();

        $rejected = $this->manager->reject($target, $targetClaim, $actor, $request)->load('user:id,name');

        return response()->json(['data' => $this->claimPayload($rejected)]);
    }

    public function assign(
        FamilySpace $familySpace,
        string $person,
        AssignPersonAccountRequest $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageAccountLink', $target);
        $membership = $this->links->activeMembership($familySpace, $request->validated('membership_id'));
        /** @var User $actor */
        $actor = $request->user();
        $link = $this->manager->assign($target, $membership, $actor, $request)->load('user:id,name');

        return response()->json(['data' => $this->linkPayload($link, $actor)]);
    }

    public function unlink(FamilySpace $familySpace, string $person, Request $request): JsonResponse
    {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageAccountLink', $target);
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->unlink($target, $actor, $request);

        return response()->json(['data' => null]);
    }

    /** @return array<string, mixed> */
    private function claimPayload(PersonAccountClaim $claim): array
    {
        return [
            'id' => $claim->id,
            'person_id' => $claim->person_id,
            'account' => [
                'id' => $claim->user_id,
                'name' => $claim->relationLoaded('user') ? $claim->user->name : null,
            ],
            'status' => $claim->status->value,
            'resolved_at' => $claim->resolved_at?->toAtomString(),
            'created_at' => $claim->created_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function linkPayload(PersonAccountLink $link, User $viewer): array
    {
        return [
            'id' => $link->id,
            'person_id' => $link->person_id,
            'account' => [
                'id' => $link->user_id,
                'name' => $link->user->name,
                'is_current_user' => $link->user_id === $viewer->id,
            ],
            'created_at' => $link->created_at?->toAtomString(),
        ];
    }
}
