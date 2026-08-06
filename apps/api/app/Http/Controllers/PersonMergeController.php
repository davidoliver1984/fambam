<?php

namespace App\Http\Controllers;

use App\Enums\PersonMergeProposalStatus;
use App\Http\Requests\MergePersonRequest;
use App\Http\Requests\ProposePersonMergeRequest;
use App\Http\Requests\ResolvePersonMergeProposalRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonMergeProposal;
use App\Models\User;
use App\Queries\PersonMergeQuery;
use App\Queries\PersonQuery;
use App\Services\PersonMergeManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonMergeController extends Controller
{
    public function __construct(
        private readonly PersonQuery $people,
        private readonly PersonMergeQuery $merges,
        private readonly PersonMergeManager $manager,
    ) {}

    public function store(FamilySpace $familySpace, string $person, MergePersonRequest $request): JsonResponse
    {
        $absorbed = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageMerge', $absorbed);
        $survivor = $this->people->findForCurrentFamilySpace((string) $request->validated('survivor_person_id'));
        Gate::authorize('manageMerge', $survivor);
        /** @var User $actor */
        $actor = $request->user();
        $merge = $this->manager->merge(
            $absorbed,
            $survivor,
            $request->validated('account_link_resolution'),
            $actor,
            $request,
        );

        return response()->json(['data' => $this->mergePayload($merge)], 201);
    }

    public function propose(
        FamilySpace $familySpace,
        string $person,
        ProposePersonMergeRequest $request,
    ): JsonResponse {
        $absorbed = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('proposeMerge', $absorbed);
        $survivor = $this->people->findForCurrentFamilySpace((string) $request->validated('survivor_person_id'));
        Gate::authorize('proposeMerge', $survivor);
        /** @var User $actor */
        $actor = $request->user();
        $proposal = $this->manager->propose(
            $absorbed,
            $survivor,
            $request->validated('context'),
            $actor,
            $request,
        );

        return response()->json(['data' => $this->proposalPayload($proposal)], 201);
    }

    public function proposals(FamilySpace $familySpace, string $person): JsonResponse
    {
        $focus = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageMerge', $focus);

        return response()->json([
            'data' => $this->merges->pendingProposals($focus)->map($this->proposalPayload(...)),
        ]);
    }

    public function approve(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        ResolvePersonMergeProposalRequest $request,
    ): JsonResponse {
        return $this->resolve($person, $proposal, PersonMergeProposalStatus::Approved, $request);
    }

    public function reject(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        ResolvePersonMergeProposalRequest $request,
    ): JsonResponse {
        return $this->resolve($person, $proposal, PersonMergeProposalStatus::Rejected, $request);
    }

    public function index(FamilySpace $familySpace, string $person): JsonResponse
    {
        $focus = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageMerge', $focus);

        return response()->json(['data' => $this->merges->forPerson($focus)->map($this->mergePayload(...))]);
    }

    public function reverse(FamilySpace $familySpace, string $merge, Request $request): JsonResponse
    {
        $target = $this->merges->find($merge);
        $survivor = $this->people->findForCurrentFamilySpace($target->survivor_person_id);
        Gate::authorize('manageMerge', $survivor);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->mergePayload(
            $this->manager->reverse($target, $actor, $request),
        )]);
    }

    private function resolve(
        string $personId,
        string $proposalId,
        PersonMergeProposalStatus $resolution,
        ResolvePersonMergeProposalRequest $request,
    ): JsonResponse {
        $focus = $this->people->findForCurrentFamilySpace($personId);
        Gate::authorize('manageMerge', $focus);
        $proposal = $this->merges->findProposal($proposalId);
        abort_unless(
            $proposal->absorbed_person_id === $focus->id || $proposal->survivor_person_id === $focus->id,
            404,
        );
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->proposalPayload(
            $this->manager->resolveProposal(
                $proposal,
                $resolution,
                $request->validated('account_link_resolution'),
                $actor,
                $request,
            ),
        )]);
    }

    /** @return array<string, mixed> */
    private function mergePayload(PersonMerge $merge): array
    {
        $people = Person::withTrashed()
            ->whereIn('id', [$merge->survivor_person_id, $merge->absorbed_person_id])
            ->get()
            ->keyBy('id');

        return [
            'id' => $merge->id,
            'survivor' => [
                'id' => $merge->survivor_person_id,
                'preferred_name' => $people->get($merge->survivor_person_id)?->preferred_name,
            ],
            'absorbed' => [
                'id' => $merge->absorbed_person_id,
                'preferred_name' => $people->get($merge->absorbed_person_id)?->preferred_name,
            ],
            'status' => $merge->status->value,
            'merged_at' => $merge->merged_at->toAtomString(),
            'reversed_at' => $merge->reversed_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function proposalPayload(PersonMergeProposal $proposal): array
    {
        $people = Person::withTrashed()
            ->whereIn('id', [$proposal->survivor_person_id, $proposal->absorbed_person_id])
            ->get()
            ->keyBy('id');

        return [
            'id' => $proposal->id,
            'survivor' => [
                'id' => $proposal->survivor_person_id,
                'preferred_name' => $people->get($proposal->survivor_person_id)?->preferred_name,
            ],
            'absorbed' => [
                'id' => $proposal->absorbed_person_id,
                'preferred_name' => $people->get($proposal->absorbed_person_id)?->preferred_name,
            ],
            'context' => $proposal->context,
            'status' => $proposal->status->value,
            'person_merge_id' => $proposal->person_merge_id,
            'created_at' => $proposal->created_at?->toAtomString(),
        ];
    }
}
