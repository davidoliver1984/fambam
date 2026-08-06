<?php

namespace App\Http\Controllers;

use App\Enums\PersonProposalStatus;
use App\Http\Requests\ProposePersonDetailsRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonDetailProposal;
use App\Models\User;
use App\People\UncertainDate;
use App\Queries\PersonQuery;
use App\Services\PersonManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonController extends Controller
{
    public function __construct(
        private readonly PersonQuery $people,
        private readonly PersonManager $personManager,
    ) {}

    public function index(FamilySpace $familySpace): JsonResponse
    {
        Gate::authorize('viewAny', Person::class);

        return response()->json([
            'data' => $this->people->listForCurrentFamilySpace()->map($this->payload(...)),
        ]);
    }

    public function store(FamilySpace $familySpace, StorePersonRequest $request): JsonResponse
    {
        Gate::authorize('create', Person::class);
        /** @var User $actor */
        $actor = $request->user();
        $person = $this->personManager->create($familySpace, $actor, $request->validated(), $request);

        return response()->json(['data' => $this->payload($person)], 201);
    }

    public function show(FamilySpace $familySpace, string $person): JsonResponse
    {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->payload($target)]);
    }

    public function update(
        FamilySpace $familySpace,
        string $person,
        UpdatePersonRequest $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('update', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json([
            'data' => $this->payload($this->personManager->update(
                $target,
                $actor,
                $request->validated(),
                $request,
            )),
        ]);
    }

    public function propose(
        FamilySpace $familySpace,
        string $person,
        ProposePersonDetailsRequest $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('propose', $target);
        /** @var User $actor */
        $actor = $request->user();
        $proposal = $this->personManager->propose($target, $actor, $request->validated(), $request);

        return response()->json(['data' => $this->proposalPayload($proposal)], 201);
    }

    public function proposals(FamilySpace $familySpace, string $person): JsonResponse
    {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('resolveProposal', $target);

        return response()->json([
            'data' => $this->people->pendingProposals($target)->map($this->proposalPayload(...)),
        ]);
    }

    public function approveProposal(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolveProposal($person, $proposal, PersonProposalStatus::Approved, $request);
    }

    public function rejectProposal(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolveProposal($person, $proposal, PersonProposalStatus::Rejected, $request);
    }

    private function resolveProposal(
        string $personId,
        string $proposalId,
        PersonProposalStatus $resolution,
        Request $request,
    ): JsonResponse {
        $person = $this->people->findForCurrentFamilySpace($personId);
        Gate::authorize('resolveProposal', $person);
        $proposal = $this->people->findProposal($person, $proposalId);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->proposalPayload(
            $this->personManager->resolveProposal($person, $proposal, $actor, $resolution, $request),
        )]);
    }

    /** @return array<string, mixed> */
    private function payload(Person $person): array
    {
        /** @var User $viewer */
        $viewer = request()->user();
        $person->loadMissing('accountLink.user:id,name');
        $accountLink = $person->accountLink;

        return [
            'id' => $person->id,
            'redirected_from_person_id' => $person->getAttribute('redirected_from_person_id'),
            'preferred_name' => $person->preferred_name,
            'alternate_names' => $person->alternate_names ?? [],
            'identity_status' => $person->identity_status->value,
            'birth_date' => UncertainDate::fromStorage(
                $person->birth_date_precision,
                $person->birth_date?->format('Y-m-d'),
            )->toPayload(),
            'is_deceased' => $person->is_deceased,
            'death_date' => UncertainDate::fromStorage(
                $person->death_date_precision,
                $person->death_date?->format('Y-m-d'),
            )->toPayload(),
            'biography' => $person->biography,
            'account_link' => $accountLink === null ? null : $this->accountLinkPayload($accountLink, $viewer),
            'created_at' => $person->created_at?->toAtomString(),
            'updated_at' => $person->updated_at?->toAtomString(),
            'permissions' => [
                'can_update_authoritatively' => Gate::allows('update', $person),
                'can_propose_changes' => Gate::allows('propose', $person),
                'can_resolve_proposals' => Gate::allows('resolveProposal', $person),
                'can_propose_account_link' => Gate::allows('proposeAccountLink', $person),
                'can_manage_account_link' => Gate::allows('manageAccountLink', $person),
                'can_propose_relationships' => Gate::allows('proposeRelationship', $person),
                'can_manage_relationships' => Gate::allows('manageRelationship', $person),
                'can_propose_merge' => Gate::allows('proposeMerge', $person),
                'can_manage_merge' => Gate::allows('manageMerge', $person),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function accountLinkPayload(PersonAccountLink $link, User $viewer): array
    {
        return [
            'id' => $link->id,
            'account' => [
                'id' => $link->user_id,
                'name' => $link->user->name,
                'is_current_user' => $link->user_id === $viewer->id,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function proposalPayload(PersonDetailProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'person_id' => $proposal->person_id,
            'changes' => $proposal->changes,
            'status' => $proposal->status->value,
            'proposed_by' => $proposal->proposed_by,
            'resolved_by' => $proposal->resolved_by,
            'resolved_at' => $proposal->resolved_at?->toAtomString(),
            'created_at' => $proposal->created_at?->toAtomString(),
        ];
    }
}
