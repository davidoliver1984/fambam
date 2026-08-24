<?php

namespace App\Queries;

use App\Enums\PhotoVisibility;
use App\Models\Photo;
use App\Models\PhotoProvenanceProposal;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhotoQuery
{
    public function __construct(private readonly TenantContext $tenantContext) {}

    /** @return Builder<Photo> */
    public function visibleTo(User $viewer): Builder
    {
        $query = Photo::query()
            ->with([
                'mediaUpload.uploader:id,name',
                'tags:id,label',
                'photographer:id,preferred_name',
                'scanner:id,preferred_name',
                'physicalOwner:id,preferred_name',
            ])
            ->where('family_space_id', $this->tenantContext->familySpace()->id);

        if (! $this->tenantContext->membership()->role->canManageMembers()) {
            $query->where(function (Builder $builder) use ($viewer): void {
                $builder->where('visibility', PhotoVisibility::FamilySpace->value)
                    ->orWhere('created_by', $viewer->id);
            });
        }

        return $query;
    }

    /** @return Collection<int, Photo> */
    public function listVisibleTo(User $viewer): Collection
    {
        return $this->visibleTo($viewer)->latest('created_at')->latest('id')->get();
    }

    public function findVisibleTo(User $viewer, string $photoId): Photo
    {
        return $this->visibleTo($viewer)->find($photoId) ?? throw new NotFoundHttpException;
    }

    public function findProposal(Photo $photo, string $proposalId): PhotoProvenanceProposal
    {
        return PhotoProvenanceProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->find($proposalId)
            ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PhotoProvenanceProposal> */
    public function pendingProposals(Photo $photo): Collection
    {
        return PhotoProvenanceProposal::query()
            ->with('person:id,preferred_name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->where('status', 'pending')
            ->oldest('created_at')
            ->get();
    }
}
