<?php

namespace App\Queries;

use App\Models\SavedSearch;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class SavedSearchQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Collection<int, SavedSearch> */
    public function listFor(User $actor): Collection
    {
        return SavedSearch::query()->with('people:id,preferred_name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('created_by', $actor->id)
            ->orderBy('name')->orderBy('id')->get();
    }

    public function findFor(User $actor, string $id): SavedSearch
    {
        return SavedSearch::query()->with('people:id,preferred_name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('created_by', $actor->id)
            ->find($id) ?? throw new NotFoundHttpException;
    }
}
