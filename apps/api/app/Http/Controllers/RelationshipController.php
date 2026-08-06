<?php

namespace App\Http\Controllers;

use App\Enums\RelationshipProposalStatus;
use App\Enums\RelationshipType;
use App\Http\Requests\ProposeRelationshipRequest;
use App\Http\Requests\ReplaceRelationshipRequest;
use App\Http\Requests\StoreRelationshipRequest;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\PersonRelationship;
use App\Models\RelationshipProposal;
use App\Models\User;
use App\Queries\PersonQuery;
use App\Queries\RelationshipQuery;
use App\Services\RelationshipManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RelationshipController extends Controller
{
    public function __construct(
        private readonly PersonQuery $people,
        private readonly RelationshipQuery $relationships,
        private readonly RelationshipManager $manager,
    ) {}

    public function index(FamilySpace $familySpace, string $person): JsonResponse
    {
        $focus = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('view', $focus);

        return response()->json([
            'data' => $this->relationships->forPerson($focus)->map(
                fn (PersonRelationship $relationship): array => $this->relationshipPayload($relationship, $focus),
            ),
        ]);
    }

    public function store(
        FamilySpace $familySpace,
        string $person,
        StoreRelationshipRequest $request,
    ): JsonResponse {
        $subject = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageRelationship', $subject);
        $related = $this->people->findForCurrentFamilySpace((string) $request->validated('related_person_id'));
        /** @var User $actor */
        $actor = $request->user();
        $relationship = $this->manager->create(
            $subject,
            $related,
            RelationshipType::from((string) $request->validated('type')),
            $request->validated('context'),
            $actor,
            $request,
        );

        return response()->json(['data' => $this->relationshipPayload($relationship, $subject)], 201);
    }

    public function update(
        FamilySpace $familySpace,
        string $relationship,
        ReplaceRelationshipRequest $request,
    ): JsonResponse {
        $target = $this->relationships->find($relationship);
        $currentSubject = $this->people->findForCurrentFamilySpace($target->subject_person_id);
        Gate::authorize('manageRelationship', $currentSubject);
        $subject = $this->people->findForCurrentFamilySpace((string) $request->validated('subject_person_id'));
        $related = $this->people->findForCurrentFamilySpace((string) $request->validated('related_person_id'));
        /** @var User $actor */
        $actor = $request->user();
        $updated = $this->manager->replace(
            $target,
            $subject,
            $related,
            RelationshipType::from((string) $request->validated('type')),
            $request->validated('context'),
            $actor,
            $request,
        );

        return response()->json(['data' => $this->relationshipPayload($updated, $subject)]);
    }

    public function destroy(FamilySpace $familySpace, string $relationship, Request $request): JsonResponse
    {
        $target = $this->relationships->find($relationship);
        $subject = $this->people->findForCurrentFamilySpace($target->subject_person_id);
        Gate::authorize('manageRelationship', $subject);
        /** @var User $actor */
        $actor = $request->user();
        $this->manager->remove($target, $actor, $request);

        return response()->json(null, 204);
    }

    public function dispute(FamilySpace $familySpace, string $relationship, Request $request): JsonResponse
    {
        $target = $this->relationships->find($relationship);
        $subject = $this->people->findForCurrentFamilySpace($target->subject_person_id);
        Gate::authorize('manageRelationship', $subject);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->relationshipPayload(
            $this->manager->dispute($target, $actor, $request),
            $subject,
        )]);
    }

    public function proposals(FamilySpace $familySpace, string $person): JsonResponse
    {
        $focus = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('manageRelationship', $focus);

        return response()->json([
            'data' => $this->relationships->pendingProposals($focus)->map($this->proposalPayload(...)),
        ]);
    }

    public function propose(
        FamilySpace $familySpace,
        string $person,
        ProposeRelationshipRequest $request,
    ): JsonResponse {
        $focus = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('proposeRelationship', $focus);
        $input = $request->validated();
        $input['subject_person_id'] = $focus->id;
        if (isset($input['relationship_id'])) {
            $relationship = $this->relationships->find((string) $input['relationship_id']);
            abort_unless(
                $relationship->subject_person_id === $focus->id || $relationship->related_person_id === $focus->id,
                404,
            );
        }
        /** @var User $actor */
        $actor = $request->user();
        $proposal = $this->manager->propose($input, $actor, $request);

        return response()->json(['data' => $this->proposalPayload($proposal)], 201);
    }

    public function approve(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolve($person, $proposal, RelationshipProposalStatus::Approved, $request);
    }

    public function reject(
        FamilySpace $familySpace,
        string $person,
        string $proposal,
        Request $request,
    ): JsonResponse {
        return $this->resolve($person, $proposal, RelationshipProposalStatus::Rejected, $request);
    }

    private function resolve(
        string $personId,
        string $proposalId,
        RelationshipProposalStatus $status,
        Request $request,
    ): JsonResponse {
        $focus = $this->people->findForCurrentFamilySpace($personId);
        Gate::authorize('manageRelationship', $focus);
        $proposal = $this->relationships->findProposal($proposalId);
        abort_unless(
            $proposal->subject_person_id === $focus->id || $proposal->related_person_id === $focus->id,
            404,
        );
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->proposalPayload(
            $this->manager->resolve($proposal, $status, $actor, $request),
        )]);
    }

    /** @return array<string, mixed> */
    private function relationshipPayload(PersonRelationship $relationship, Person $focus): array
    {
        $relationship->loadMissing(['subject:id,preferred_name', 'related:id,preferred_name']);
        $forward = $relationship->subject_person_id === $focus->id;
        $other = $forward ? $relationship->related : $relationship->subject;

        return [
            'id' => $relationship->id,
            'subject_person_id' => $relationship->subject_person_id,
            'related_person_id' => $relationship->related_person_id,
            'type' => $relationship->type->value,
            'status' => $relationship->status->value,
            'label' => $forward ? $relationship->type->forwardLabel() : $relationship->type->inverseLabel(),
            'other_person' => ['id' => $other->id, 'preferred_name' => $other->preferred_name],
            'context' => $relationship->context,
        ];
    }

    /** @return array<string, mixed> */
    private function proposalPayload(RelationshipProposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'action' => $proposal->action->value,
            'relationship_id' => $proposal->relationship_id,
            'subject_person_id' => $proposal->subject_person_id,
            'related_person_id' => $proposal->related_person_id,
            'type' => $proposal->type?->value,
            'context' => $proposal->context,
            'status' => $proposal->status->value,
            'created_at' => $proposal->created_at?->toAtomString(),
        ];
    }
}
