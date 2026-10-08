<?php

namespace App\Queries;

use App\Enums\PersonProposalStatus;
use App\Models\Person;
use App\Models\PersonDetailProposal;
use App\People\PersonCursor;
use App\People\PersonCursorCodec;
use App\People\PersonListCriteria;
use App\People\PersonListPage;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PersonQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly PersonMergeQuery $merges,
        private readonly PersonCursorCodec $cursors,
    ) {}

    /** @return Builder<Person> */
    public function forCurrentFamilySpace(): Builder
    {
        return Person::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id);
    }

    public function pageForCurrentFamilySpace(PersonListCriteria $criteria): PersonListPage
    {
        $query = $this->forCurrentFamilySpace()
            ->select('people.*')
            ->selectRaw('LOWER(TRIM(people.preferred_name)) AS people_sort_name');
        $this->applyFilters($query, $criteria);
        $cursor = $this->cursors->decode($criteria->cursor, $criteria);
        $this->applySortAndCursor($query, $criteria, $cursor);

        /** @var Collection<int, Person> $items */
        $items = $query->limit($criteria->limit + 1)->get();
        $hasMore = $items->count() > $criteria->limit;
        if ($hasMore) {
            $items->pop();
        }
        $last = $items->last();
        $nextCursor = $hasMore && $last instanceof Person
            ? $this->cursors->encode(new PersonCursor(
                $criteria->sort,
                $criteria->fingerprint(),
                (string) $last->getAttribute('people_sort_name'),
                $last->id,
            ))
            : null;

        return new PersonListPage($items->values(), $nextCursor);
    }

    /** @param Builder<Person> $query */
    private function applyFilters(Builder $query, PersonListCriteria $criteria): void
    {
        if ($criteria->term !== null) {
            $term = '%'.$criteria->term.'%';
            $query->where(function (Builder $matches) use ($term): void {
                $matches->whereRaw('LOWER(people.preferred_name) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(CAST(COALESCE(people.alternate_names, '[]') AS TEXT)) LIKE ?", [$term]);
            });
        }
        if ($criteria->status === 'living') {
            $query->whereNull('people.death_date');
        } elseif ($criteria->status === 'remembered') {
            $query->whereNotNull('people.death_date');
        }
    }

    /** @param Builder<Person> $query */
    private function applySortAndCursor(
        Builder $query,
        PersonListCriteria $criteria,
        ?PersonCursor $cursor,
    ): void {
        $direction = $criteria->sort === 'za' ? 'desc' : 'asc';
        $operator = $direction === 'asc' ? '>' : '<';
        if ($cursor !== null) {
            $query->where(function (Builder $after) use ($cursor, $operator): void {
                $after->whereRaw("LOWER(TRIM(people.preferred_name)) {$operator} ?", [$cursor->name])
                    ->orWhere(function (Builder $tie) use ($cursor, $operator): void {
                        $tie->whereRaw('LOWER(TRIM(people.preferred_name)) = ?', [$cursor->name])
                            ->where('people.id', $operator, $cursor->id);
                    });
            });
        }
        $query->orderByRaw('LOWER(TRIM(people.preferred_name)) '.$direction)
            ->orderBy('people.id', $direction);
    }

    public function findForCurrentFamilySpace(string $personId): Person
    {
        $person = Person::withTrashed()
            ->with('accountLink.user:id,name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($personId)
            ?? throw new NotFoundHttpException;
        if ($person->deleted_at === null) {
            return $person;
        }

        $merge = $this->merges->redirectFor($person->id)
            ?? throw new NotFoundHttpException;
        $survivor = $this->forCurrentFamilySpace()->find($merge->survivor_person_id)
            ?? throw new NotFoundHttpException;
        $survivor->setAttribute('redirected_from_person_id', $person->id);

        return $survivor;
    }

    public function findProposal(Person $person, string $proposalId): PersonDetailProposal
    {
        return PersonDetailProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->find($proposalId)
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PersonDetailProposal> */
    public function pendingProposals(Person $person): Collection
    {
        return PersonDetailProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('person_id', $person->id)
            ->where('status', PersonProposalStatus::Pending->value)
            ->orderBy('created_at')
            ->get();
    }
}
