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

        $membership = $this->tenantContext->membership();
        if (! $membership->role->canManageMembers()) {
            $query->where(function (Builder $builder) use ($viewer, $membership): void {
                $builder->where(function (Builder $intrinsic) use ($membership): void {
                    $intrinsic->where('visibility', PhotoVisibility::FamilySpace->value);
                    if ($membership->role->value !== 'member') {
                        $intrinsic->whereRaw('1 = 0');
                    }
                })
                    ->orWhere('created_by', $viewer->id)
                    ->orWhereHas('albums', function (Builder $album) use ($viewer, $membership): void {
                        $album->where('created_by', $viewer->id)
                            ->orWhere(function (Builder $family) use ($membership): void {
                                $family->where('albums.visibility', 'family_space');
                                if ($membership->role->value !== 'member') {
                                    $family->whereRaw('1 = 0');
                                }
                            })
                            ->orWhereHas('grants', fn (Builder $grant) => $grant
                                ->where('family_space_membership_id', $membership->id)->where('can_view', true));
                    });
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Photo>
     */
    public function listVisibleTo(User $viewer, array $filters = []): Collection
    {
        $query = $this->visibleTo($viewer);
        if (! empty($filters['person_id'])) {
            $query->whereHas('photoPeople', fn (Builder $people) => $people
                ->where('person_id', $filters['person_id'])->where('status', 'approved'));
        }
        if (! empty($filters['tag'])) {
            $query->whereHas('tags', fn (Builder $tags) => $tags
                ->where('normalized_label', mb_strtolower(trim($filters['tag']))));
        }
        if (! empty($filters['location'])) {
            $query->where('location_description', 'like', '%'.addcslashes($filters['location'], '%_').'%');
        }
        if (! empty($filters['historical_year'])) {
            $query->whereYear('historical_date', (int) $filters['historical_year']);
        }
        if (($filters['without_confirmed_date'] ?? false) === true) {
            $query->whereNull('historical_date_precision');
        }

        return $query->latest('created_at')->latest('id')->get();
    }

    public function findVisibleTo(User $viewer, string $photoId): Photo
    {
        return $this->visibleTo($viewer)->find($photoId) ?? throw new NotFoundHttpException;
    }

    /** @return Collection<int, Photo> */
    public function deletedManageableBy(User $viewer): Collection
    {
        $query = Photo::onlyTrashed()->with('mediaUpload')
            ->where('family_space_id', $this->tenantContext->familySpace()->id);
        if (! $this->tenantContext->membership()->role->canManageMembers()) {
            $query->where('created_by', $viewer->id);
        }

        return $query->latest('deleted_at')->get();
    }

    public function findDeletedManageableBy(User $viewer, string $photoId): Photo
    {
        return $this->deletedManageableBy($viewer)->firstWhere('id', $photoId)
            ?? throw new NotFoundHttpException;
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
