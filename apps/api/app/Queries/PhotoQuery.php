<?php

namespace App\Queries;

use App\Enums\PhotoVisibility;
use App\Models\Photo;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
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
                'photoPeople' => fn ($query) => $query
                    ->where('status', 'approved')
                    ->with('person:id,preferred_name'),
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

    public function findMetadataProposal(Photo $photo, string $proposalId): PhotoMetadataProposal
    {
        return PhotoMetadataProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->find($proposalId) ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PhotoMetadataProposal> */
    public function pendingMetadataProposals(Photo $photo): Collection
    {
        return PhotoMetadataProposal::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->where('status', 'pending')
            ->oldest('created_at')->get();
    }

    public function findPhotoPerson(Photo $photo, string $associationId): PhotoPerson
    {
        return PhotoPerson::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->find($associationId) ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, PhotoPerson> */
    public function pendingPhotoPeople(Photo $photo): Collection
    {
        return PhotoPerson::query()->with('person:id,preferred_name')
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('photo_id', $photo->id)
            ->where('status', 'pending')
            ->oldest('created_at')->get();
    }
}
