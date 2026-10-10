<?php

namespace App\Http\Controllers;

use App\Enums\PersonProposalStatus;
use App\Enums\RelationshipStatus;
use App\FaceRecognition\RecognitionConsentManager;
use App\Http\Requests\ListPeopleRequest;
use App\Http\Requests\ProposePersonDetailsRequest;
use App\Http\Requests\StorePersonRequest;
use App\Http\Requests\UpdatePersonRequest;
use App\Http\Requests\UpdateRecognitionConsentRequest;
use App\Models\Album;
use App\Models\FamilySpace;
use App\Models\Person;
use App\Models\PersonAccountLink;
use App\Models\PersonDetailProposal;
use App\Models\PersonRelationship;
use App\Models\User;
use App\People\UncertainDate;
use App\Queries\AlbumQuery;
use App\Queries\PersonQuery;
use App\Queries\PersonRecognitionSummaryQuery;
use App\Queries\RelationshipQuery;
use App\Services\PersonManager;
use App\Services\PresentationThumbnailService;
use App\Stories\RichTextPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PersonController extends Controller
{
    private const int FEATURED_ALBUM_LIMIT = 6;

    public function __construct(
        private readonly PersonQuery $people,
        private readonly AlbumQuery $albums,
        private readonly PersonManager $personManager,
        private readonly RecognitionConsentManager $recognitionConsent,
        private readonly RichTextPresenter $presenter,
        private readonly PresentationThumbnailService $thumbnails,
        private readonly RelationshipQuery $relationships,
        private readonly PersonRecognitionSummaryQuery $recognitionSummary,
    ) {}

    public function index(FamilySpace $familySpace, ListPeopleRequest $request): JsonResponse
    {
        Gate::authorize('viewAny', Person::class);
        /** @var User $viewer */
        $viewer = $request->user();
        $page = $this->people->pageForCurrentFamilySpace($request->criteria($familySpace->id));
        $personIds = $page->items->pluck('id')->all();
        $portraits = $this->thumbnails->forPeople($personIds, $viewer);
        $relationships = $this->relationships->summariesToViewer($personIds, $viewer);

        return response()->json(['data' => [
            'items' => $page->items->map(fn (Person $person): array => $this->summaryPayload(
                $person,
                $portraits[$person->id] ?? null,
                $relationships[$person->id] ?? null,
            ))->values(),
            'next_cursor' => $page->nextCursor,
        ]]);
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

    public function updateRecognitionConsent(
        FamilySpace $familySpace,
        string $person,
        UpdateRecognitionConsentRequest $request,
    ): JsonResponse {
        $target = $this->people->findForCurrentFamilySpace($person);
        Gate::authorize('update', $target);
        /** @var User $actor */
        $actor = $request->user();

        return response()->json(['data' => $this->payload($this->recognitionConsent->set(
            $target,
            (bool) $request->validated('recognition_allowed'),
            $actor,
            $request,
        ))]);
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
        $person->loadMissing(['accountLink.user:id,name', 'knownFor']);
        $accountLink = $person->accountLink;
        $featuredAlbums = $this->albums->featuringPerson($viewer, $person->id)
            ->setEagerLoads([])
            ->with('coverPhoto:id,media_upload_id')
            ->orderByRaw('CASE WHEN albums.starts_on IS NULL THEN 1 ELSE 0 END ASC')
            ->orderByDesc('albums.starts_on')
            ->orderByDesc('albums.id')
            ->limit(self::FEATURED_ALBUM_LIMIT)
            ->get([
                'albums.id', 'albums.name', 'albums.starts_on', 'albums.ends_on', 'albums.location',
                'albums.cover_photo_id', 'albums.cover_focal_x', 'albums.cover_focal_y',
            ]);
        $coverUrls = $this->thumbnails->forMediaUploads(
            $featuredAlbums->pluck('coverPhoto.media_upload_id')->filter()->values()->all(),
        );

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
            'birth_place' => $person->birth_place,
            'is_deceased' => $person->is_deceased,
            'death_date' => UncertainDate::fromStorage(
                $person->death_date_precision,
                $person->death_date?->format('Y-m-d'),
            )->toPayload(),
            'death_place' => $person->death_place,
            'residence_place' => $person->residence_place,
            'biography' => $person->biography_plain_text,
            'biography_document' => $person->biography,
            'biography_html' => $this->presenter->html($person->biography, $person, 'person_biography_mentions',
                'biography_person_id', $this->familySlug(), $this->actor(), $person),
            'profile_quote' => $person->profile_quote,
            'profile_quote_attribution' => $person->profile_quote_attribution,
            'known_for' => $person->knownFor->pluck('label')->values()->all(),
            'featured_albums' => $featuredAlbums->map(fn (Album $album): array => [
                'id' => $album->id,
                'name' => $album->name,
                'starts_on' => $album->starts_on?->format('Y-m-d'),
                'ends_on' => $album->ends_on?->format('Y-m-d'),
                'location' => $album->location,
                'cover_thumbnail_url' => $album->coverPhoto === null
                    ? null
                    : ($coverUrls[$album->coverPhoto->media_upload_id] ?? null),
                'cover_focal_x' => $album->cover_photo_id === null ? null : (float) $album->cover_focal_x,
                'cover_focal_y' => $album->cover_photo_id === null ? null : (float) $album->cover_focal_y,
            ])->values()->all(),
            'relationships' => $this->relationships->confirmedForPerson($person)->map(
                fn (PersonRelationship $relationship): array => $this->relationshipPayload($relationship, $person),
            )->values()->all(),
            'recognition_summary' => $this->recognitionSummary->forPerson(
                $this->familySpace(),
                $person,
                $viewer,
            ),
            'recognition_allowed' => $person->recognition_allowed,
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
                'can_manage_recognition' => Gate::allows('update', $person),
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

    /**
     * @param  array{type: string, label: string}|null  $relationship
     * @return array<string, mixed>
     */
    private function summaryPayload(Person $person, ?string $portraitThumbnailUrl, ?array $relationship): array
    {
        return [
            'id' => $person->id,
            'preferred_name' => $person->preferred_name,
            'alternate_names' => $person->alternate_names ?? [],
            'identity_status' => $person->identity_status->value,
            'birth_date' => UncertainDate::fromStorage(
                $person->birth_date_precision,
                $person->birth_date?->format('Y-m-d'),
            )->toPayload(),
            'death_date' => UncertainDate::fromStorage(
                $person->death_date_precision,
                $person->death_date?->format('Y-m-d'),
            )->toPayload(),
            'status' => $person->death_date === null ? 'living' : 'remembered',
            'portrait_thumbnail_url' => $portraitThumbnailUrl,
            'relationship_summary' => $relationship,
        ];
    }

    private function familySlug(): string
    {
        $familySpace = request()->route('familySpace');

        return $familySpace instanceof FamilySpace ? $familySpace->slug : (string) $familySpace;
    }

    private function familySpace(): FamilySpace
    {
        $familySpace = request()->route('familySpace');
        abort_unless($familySpace instanceof FamilySpace, 404);

        return $familySpace;
    }

    private function actor(): User
    {
        $actor = request()->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
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

    /** @return array<string, mixed> */
    private function relationshipPayload(PersonRelationship $relationship, Person $focus): array
    {
        $forward = $relationship->subject_person_id === $focus->id;
        $other = $forward ? $relationship->related : $relationship->subject;

        return [
            'id' => $relationship->id,
            'subject_person_id' => $relationship->subject_person_id,
            'related_person_id' => $relationship->related_person_id,
            'type' => $relationship->type->value,
            'status' => RelationshipStatus::Confirmed->value,
            'label' => $forward ? $relationship->type->forwardLabel() : $relationship->type->inverseLabel(),
            'other_person' => ['id' => $other->id, 'preferred_name' => $other->preferred_name],
            'context' => $relationship->context,
            'relationship_started_on' => UncertainDate::fromStorage(
                $relationship->relationship_started_on_precision,
                $relationship->relationship_started_on?->format('Y-m-d'),
            )->toPayload(),
            'created_at' => $relationship->created_at?->toAtomString(),
        ];
    }
}
