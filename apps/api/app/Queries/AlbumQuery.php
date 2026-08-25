<?php

namespace App\Queries;

use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Models\Album;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AlbumQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Builder<Album> */
    public function visibleTo(User $viewer): Builder
    {
        $membership = $this->tenantContext->membership();
        $query = Album::query()->with(['creator:id,name', 'event:id,name,starts_on', 'photos.mediaUpload'])
            ->where('family_space_id', $this->tenantContext->familySpace()->id);
        if ($membership->role === FamilySpaceRole::Guest) {
            return $query->whereRaw('1 = 0');
        }
        if (! $membership->role->canManageMembers()) {
            $query->where(function (Builder $builder) use ($viewer, $membership): void {
                $builder->where('created_by', $viewer->id)
                    ->orWhere(function (Builder $family) use ($membership): void {
                        $family->where('visibility', AlbumVisibility::FamilySpace->value);
                        if ($membership->role !== FamilySpaceRole::Member) {
                            $family->whereRaw('1 = 0');
                        }
                    })
                    ->orWhereHas('grants', fn (Builder $grant) => $grant
                        ->where('family_space_membership_id', $membership->id)->where('can_view', true));
            });
        }

        return $query;
    }

    /** @return Collection<int, Album> */
    public function listVisibleTo(User $viewer): Collection
    {
        return $this->visibleTo($viewer)->latest()->get();
    }

    public function findVisibleTo(User $viewer, string $id): Album
    {
        return $this->visibleTo($viewer)->find($id) ?? throw new NotFoundHttpException;
    }
}
