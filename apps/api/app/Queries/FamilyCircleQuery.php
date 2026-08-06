<?php

namespace App\Queries;

use App\Models\FamilyCircle;
use App\Models\Person;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class FamilyCircleQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, FamilyCircle> */
    public function all(): Collection
    {
        return FamilyCircle::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->with(['people' => fn ($query) => $query->orderBy('preferred_name')])
            ->orderBy('name')
            ->get();
    }

    public function find(string $circleId): FamilyCircle
    {
        return FamilyCircle::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($circleId)
            ?? throw new NotFoundHttpException;
    }

    public function findPerson(string $personId): Person
    {
        return Person::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->find($personId)
            ?? throw new NotFoundHttpException;
    }
}
