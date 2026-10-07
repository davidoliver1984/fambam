<?php

namespace App\Queries;

use App\Enums\FamilySpaceRole;
use App\Enums\MediaUploadState;
use App\Enums\PhotoVisibility;
use App\Models\FamilySpaceMembership;
use App\Models\MediaUpload;
use App\Models\Photo;
use App\Models\PhotoMetadataProposal;
use App\Models\PhotoPerson;
use App\Models\PhotoProvenanceProposal;
use App\Models\User;
use App\Photos\PhotoCursor;
use App\Photos\PhotoCursorCodec;
use App\Photos\PhotoListCriteria;
use App\Photos\PhotoListPage;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PhotoQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly AlbumQuery $albums,
        private readonly PhotoCursorCodec $cursors,
    ) {}

    /** @return Builder<Photo> */
    public function visibleTo(User $viewer): Builder
    {
        return $this->visibleToMembership(
            $viewer,
            $this->tenantContext->membership(),
            $this->tenantContext->familySpace()->id,
        );
    }

    /** @return Builder<Photo> */
    public function visibleToMembership(
        User $viewer,
        FamilySpaceMembership $membership,
        string $familySpaceId,
    ): Builder {
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
            ->where('photos.family_space_id', $familySpaceId);

        if ($membership->role === FamilySpaceRole::Guest) {
            $cutoff = now()->subDays((int) config('events.admission_lifetime_days'));

            return $query->whereHas('albums', fn (Builder $albums) => $albums
                ->whereNotNull('event_id')
                ->whereHas('event')
                ->whereHas('event.admissions', fn (Builder $admissions) => $admissions
                    ->where('family_space_membership_id', $membership->id)
                    ->whereNull('revoked_at')
                    ->where('admitted_at', '>', $cutoff))
                ->where(function (Builder $access) use ($membership): void {
                    $access->whereIn('guest_participation', ['view', 'contribute'])
                        ->orWhereHas('grants', fn (Builder $grant) => $grant
                            ->where('family_space_membership_id', $membership->id)
                            ->where('can_view', true));
                }));
        }
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

    public function pageVisibleTo(User $viewer, PhotoListCriteria $criteria): PhotoListPage
    {
        $familySpaceId = $this->tenantContext->familySpace()->id;
        $query = $this->visibleTo($viewer)
            ->select('photos.*')
            ->selectSub(
                $this->interactionAlbumIdQuery($viewer, $familySpaceId),
                'interaction_album_id',
            )
            ->selectSub(
                $this->viewerHasLovedInteractionQuery($viewer, $familySpaceId),
                'viewer_has_loved',
            )
            ->withCount($this->listAggregates($viewer, $familySpaceId));
        $this->applyFilters($query, $criteria, $familySpaceId);
        $cursor = $this->cursors->decode($criteria->cursor, $criteria);
        $this->applySortAndCursor($query, $criteria, $cursor);

        /** @var Collection<int, Photo> $items */
        $items = $query->limit($criteria->limit + 1)->get();
        $hasMore = $items->count() > $criteria->limit;
        if ($hasMore) {
            $items->pop();
        }
        $last = $items->last();
        $nextCursor = $hasMore && $last instanceof Photo
            ? $this->cursors->encode($this->cursorFor($last, $criteria))
            : null;

        return new PhotoListPage($items->values(), $nextCursor);
    }

    /** @param Builder<Photo> $query */
    private function applyFilters(Builder $query, PhotoListCriteria $criteria, string $familySpaceId): void
    {
        if ($criteria->term !== null) {
            $this->applySearch($query, $criteria->term);
        }
        if ($criteria->personId !== null) {
            $query->whereHas('photoPeople', fn (Builder $people) => $people
                ->where('person_id', $criteria->personId)->where('status', 'approved'));
        }
        if ($criteria->tag !== null) {
            $query->whereHas('tags', fn (Builder $tags) => $tags
                ->where('normalized_label', mb_strtolower($criteria->tag)));
        }
        if ($criteria->location !== null) {
            $query->whereRaw("LOWER(COALESCE(photos.location_description, '')) LIKE ?", [
                '%'.mb_strtolower(addcslashes($criteria->location, '%_')).'%',
            ]);
        }
        if ($criteria->historicalYear !== null) {
            $query->whereYear('historical_date', $criteria->historicalYear);
        }
        if ($criteria->withoutConfirmedDate) {
            $query->whereNull('historical_date_precision');
        }
        if ($criteria->withoutAlbum) {
            $query->whereDoesntHave('albumPhotos', fn (Builder $memberships) => $memberships
                ->where('album_photos.family_space_id', $familySpaceId));
        }
    }

    /** @param Builder<Photo> $query */
    private function applySearch(Builder $query, string $term): void
    {
        $like = '%'.mb_strtolower(addcslashes($term, '%_')).'%';
        $dateRange = $this->historicalDateRange($term);
        $query->where(function (Builder $matches) use ($term, $like, $dateRange): void {
            if (DB::getDriverName() === 'pgsql') {
                $matches->whereRaw("photos.search_vector @@ websearch_to_tsquery('simple'::regconfig, ?)", [$term]);
            } else {
                $matches->whereRaw(
                    "LOWER(COALESCE(photos.caption, '') || ' ' || COALESCE(photos.description, '') || ' ' || COALESCE(photos.archive_source_description, '') || ' ' || COALESCE(photos.location_description, '')) LIKE ?",
                    [$like],
                );
            }
            $matches->orWhereHas('mediaUpload', fn (Builder $uploads) => $uploads
                ->whereRaw('LOWER(media_uploads.client_filename) LIKE ?', [$like]))
                ->orWhereHas('photoPeople', fn (Builder $people) => $people
                    ->where('photo_people.status', 'approved')
                    ->whereHas('person', fn (Builder $person) => $person
                        ->whereRaw('LOWER(people.preferred_name) LIKE ?', [$like])))
                ->orWhereHas('tags', fn (Builder $tags) => $tags
                    ->whereRaw('LOWER(tags.label) LIKE ?', [$like]))
                ->orWhereRaw('CAST(photos.historical_date AS TEXT) LIKE ?', [$like]);
            if ($dateRange !== null) {
                $matches->orWhereBetween('photos.historical_date', $dateRange);
            }
        });
    }

    /** @return array{string, string}|null */
    private function historicalDateRange(string $term): ?array
    {
        $months = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        ];
        $normalized = mb_strtolower(trim($term));
        if (preg_match('/^(?:(\d{1,2})\s+)?([a-z]+)\s+(\d{4})$/', $normalized, $matches) === 1
            && isset($months[$matches[2]])) {
            $year = (int) $matches[3];
            $month = $months[$matches[2]];
            if ($matches[1] !== '') {
                try {
                    $date = CarbonImmutable::create($year, $month, (int) $matches[1])->startOfDay();
                } catch (\Throwable) {
                    return null;
                }

                return [$date->format('Y-m-d'), $date->format('Y-m-d')];
            }
            $start = CarbonImmutable::create($year, $month, 1)->startOfMonth();

            return [$start->format('Y-m-d'), $start->endOfMonth()->format('Y-m-d')];
        }
        if (preg_match('/^\d{4}$/', $normalized) === 1) {
            return ["{$normalized}-01-01", "{$normalized}-12-31"];
        }

        return null;
    }

    /** @param Builder<Photo> $query */
    private function applySortAndCursor(Builder $query, PhotoListCriteria $criteria, ?PhotoCursor $cursor): void
    {
        if ($criteria->sort === 'recently_added') {
            if ($cursor !== null) {
                $query->where(function (Builder $after) use ($cursor): void {
                    $after->where('photos.created_at', '<', $cursor->value)
                        ->orWhere(function (Builder $tie) use ($cursor): void {
                            $tie->where('photos.created_at', $cursor->value)->where('photos.id', '<', $cursor->id);
                        });
                });
            }
            $query->orderByDesc('photos.created_at')->orderByDesc('photos.id');

            return;
        }

        $direction = $criteria->sort === 'oldest' ? 'asc' : 'desc';
        if ($cursor !== null) {
            $this->applyHistoricalDateCursor($query, $cursor, $direction);
        }
        // The frozen Newest/Oldest modes mean the archival Photo date. An
        // undated Photo is never assigned an upload date and stays last in both.
        $query->orderByRaw('CASE WHEN photos.historical_date IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('photos.historical_date', $direction)
            ->orderBy('photos.id', $direction);
    }

    /** @param Builder<Photo> $query */
    private function applyHistoricalDateCursor(Builder $query, PhotoCursor $cursor, string $direction): void
    {
        $operator = $direction === 'asc' ? '>' : '<';
        $query->where(function (Builder $after) use ($cursor, $operator): void {
            if ($cursor->nullRank === 0) {
                $after->whereNull('photos.historical_date')
                    ->orWhere(function (Builder $dated) use ($cursor, $operator): void {
                        $dated->whereNotNull('photos.historical_date')
                            ->where(function (Builder $position) use ($cursor, $operator): void {
                                $position->whereDate('photos.historical_date', $operator, $cursor->value)
                                    ->orWhere(function (Builder $tie) use ($cursor, $operator): void {
                                        $tie->whereDate('photos.historical_date', $cursor->value)
                                            ->where('photos.id', $operator, $cursor->id);
                                    });
                            });
                    });

                return;
            }
            $after->whereNull('photos.historical_date')->where('photos.id', $operator, $cursor->id);
        });
    }

    private function cursorFor(Photo $photo, PhotoListCriteria $criteria): PhotoCursor
    {
        if ($criteria->sort === 'recently_added') {
            return new PhotoCursor(
                $criteria->sort,
                $criteria->fingerprint(),
                0,
                $photo->created_at?->format('Y-m-d H:i:s'),
                $photo->id,
            );
        }

        return new PhotoCursor(
            $criteria->sort,
            $criteria->fingerprint(),
            $photo->historical_date === null ? 1 : 0,
            $photo->historical_date?->format('Y-m-d'),
            $photo->id,
        );
    }

    public function loadListAggregates(Photo $photo, User $viewer): void
    {
        if (collect(['love_count', 'comment_count', 'album_count'])
            ->every(fn (string $attribute): bool => array_key_exists($attribute, $photo->getAttributes()))) {
            return;
        }

        $photo->loadCount($this->listAggregates(
            $viewer,
            $this->tenantContext->familySpace()->id,
        ));
    }

    /** @return array<string, callable(Builder<Model>): void> */
    private function listAggregates(User $viewer, string $familySpaceId): array
    {
        return [
            'reactions as love_count' => function (Builder $reactions) use ($viewer, $familySpaceId): void {
                $reactions->where('photo_reactions.family_space_id', $familySpaceId)
                    ->where('photo_reactions.reaction', 'love');
                $this->scopeToReadableConversationContexts(
                    $reactions,
                    $viewer,
                    $familySpaceId,
                    'photo_reactions',
                );
            },
            'comments as comment_count' => function (Builder $comments) use ($viewer, $familySpaceId): void {
                $comments->where('photo_comments.family_space_id', $familySpaceId);
                $this->scopeToReadableConversationContexts(
                    $comments,
                    $viewer,
                    $familySpaceId,
                    'photo_comments',
                );
            },
            'albumPhotos as album_count' => function (Builder $memberships) use ($familySpaceId): void {
                $memberships->where('album_photos.family_space_id', $familySpaceId);
            },
        ];
    }

    private function interactionAlbumIdQuery(User $viewer, string $familySpaceId): QueryBuilder
    {
        return DB::table('album_photos')
            ->select('album_photos.album_id')
            ->whereColumn('album_photos.photo_id', 'photos.id')
            ->where('album_photos.family_space_id', $familySpaceId)
            ->whereIn(
                'album_photos.album_id',
                $this->albums->visibleTo($viewer)->select('albums.id'),
            )
            ->latest('album_photos.created_at')
            ->latest('album_photos.id')
            ->limit(1);
    }

    private function viewerHasLovedInteractionQuery(User $viewer, string $familySpaceId): QueryBuilder
    {
        return DB::table('photo_reactions')
            ->selectRaw('CASE WHEN count(*) > 0 THEN 1 ELSE 0 END')
            ->whereColumn('photo_reactions.photo_id', 'photos.id')
            ->where('photo_reactions.family_space_id', $familySpaceId)
            ->where('photo_reactions.user_id', $viewer->id)
            ->where('photo_reactions.reaction', 'love')
            ->where(
                'photo_reactions.album_id',
                '=',
                $this->interactionAlbumIdQuery($viewer, $familySpaceId),
            );
    }

    /** @param Builder<Model> $query */
    private function scopeToReadableConversationContexts(
        Builder $query,
        User $viewer,
        string $familySpaceId,
        string $table,
    ): void {
        $visibleAlbums = $this->albums->visibleTo($viewer)->select('albums.id');

        $query->where(function (Builder $contexts) use ($visibleAlbums, $familySpaceId, $table): void {
            $contexts->whereNull("{$table}.album_id")
                ->orWhere(function (Builder $albumContext) use ($visibleAlbums, $familySpaceId, $table): void {
                    $albumContext->whereIn("{$table}.album_id", $visibleAlbums)
                        ->whereExists(function ($memberships) use ($familySpaceId, $table): void {
                            $memberships->selectRaw('1')
                                ->from('album_photos')
                                ->whereColumn('album_photos.album_id', "{$table}.album_id")
                                ->whereColumn('album_photos.photo_id', "{$table}.photo_id")
                                ->where('album_photos.family_space_id', $familySpaceId);
                        });
                });
        });
    }

    /** @return Collection<int, MediaUpload> */
    public function promotableUploads(User $viewer): Collection
    {
        $membership = $this->tenantContext->membership();
        $query = MediaUpload::query()
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->where('purpose', 'archive')
            ->where('state', MediaUploadState::Ready)
            ->whereNull('target_album_id')
            ->whereDoesntHave('photo');

        if (! $membership->role->canManageMembers()) {
            $query->where('user_id', $viewer->id);
        }

        return $query->latest('uploaded_at')->latest('id')->get();
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
