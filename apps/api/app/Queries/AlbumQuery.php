<?php

namespace App\Queries;

use App\Albums\AlbumCursor;
use App\Albums\AlbumCursorCodec;
use App\Albums\AlbumListCriteria;
use App\Albums\AlbumListPage;
use App\Enums\AlbumVisibility;
use App\Enums\FamilySpaceRole;
use App\Models\Album;
use App\Models\User;
use App\Services\EventAccess;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AlbumQuery
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EventAccess $eventAccess,
        private readonly AlbumCursorCodec $cursors,
    ) {}

    /** @return Builder<Album> */
    public function visibleTo(User $viewer): Builder
    {
        $membership = $this->tenantContext->membership();
        $query = Album::query()->with(['creator:id,name', 'event:id,name,starts_on'])
            ->where('family_space_id', $this->tenantContext->familySpace()->id)
            ->whereNull('deleting_at');
        if ($membership->role === FamilySpaceRole::Guest) {
            return $this->eventAccess->scopeAlbumsForGuest($query, $membership);
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
        return $this->visibleTo($viewer)->withCount('albumPhotos')->latest()->get();
    }

    public function pageVisibleTo(User $viewer, AlbumListCriteria $criteria): AlbumListPage
    {
        $query = $this->visibleTo($viewer)
            ->select('albums.*')
            ->withCount('albumPhotos');
        $this->applyFilters($query, $criteria);
        $cursor = $this->cursors->decode($criteria->cursor, $criteria);
        $this->applySortAndCursor($query, $criteria, $cursor);

        /** @var Collection<int, Album> $items */
        $items = $query->limit($criteria->limit + 1)->get();
        $hasMore = $items->count() > $criteria->limit;
        if ($hasMore) {
            $items->pop();
        }
        $last = $items->last();
        $nextCursor = $hasMore && $last instanceof Album
            ? $this->cursors->encode($this->cursorFor($last, $criteria))
            : null;

        return new AlbumListPage($items->values(), $nextCursor);
    }

    public function findVisibleTo(User $viewer, string $id): Album
    {
        return $this->visibleTo($viewer)->find($id) ?? throw new NotFoundHttpException;
    }

    /** @param Builder<Album> $query */
    private function applyFilters(Builder $query, AlbumListCriteria $criteria): void
    {
        if ($criteria->term !== null) {
            $term = '%'.Str::lower($criteria->term).'%';
            $query->where(function (Builder $matches) use ($term): void {
                $matches->whereRaw('LOWER(albums.name) LIKE ?', [$term])
                    ->orWhereRaw("LOWER(COALESCE(albums.description_plain_text, '')) LIKE ?", [$term])
                    ->orWhereRaw("LOWER(COALESCE(albums.location, '')) LIKE ?", [$term])
                    ->orWhereRaw('CAST(albums.starts_on AS TEXT) LIKE ?', [$term])
                    ->orWhereRaw('CAST(albums.ends_on AS TEXT) LIKE ?', [$term])
                    ->orWhereHas('creator', fn (Builder $creator) => $creator->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('people', fn (Builder $people) => $people->whereRaw('LOWER(preferred_name) LIKE ?', [$term]))
                    ->orWhereHas('event', fn (Builder $event) => $event->whereRaw('LOWER(name) LIKE ?', [$term]))
                    ->orWhereHas('tags', fn (Builder $tags) => $tags->whereRaw('LOWER(label) LIKE ?', [$term]));
            });
        }
        if ($criteria->location !== null) {
            $query->whereRaw('LOWER(albums.location) LIKE ?', ['%'.Str::lower($criteria->location).'%']);
        }
        if ($criteria->dateFrom !== null) {
            $query->whereNotNull('albums.starts_on')
                ->whereRaw('COALESCE(albums.ends_on, albums.starts_on) >= ?', [$criteria->dateFrom]);
        }
        if ($criteria->dateTo !== null) {
            $query->whereNotNull('albums.starts_on')->whereDate('albums.starts_on', '<=', $criteria->dateTo);
        }
        if ($criteria->tagId !== null) {
            $query->whereHas('tags', fn (Builder $tags) => $tags->where('tags.id', $criteria->tagId));
        }
        if ($criteria->personIds !== []) {
            $query->whereHas('people', fn (Builder $people) => $people->whereIn('people.id', $criteria->personIds));
        }
        if ($criteria->eventId !== null) {
            $query->where('albums.event_id', $criteria->eventId);
        }
    }

    /** @param Builder<Album> $query */
    private function applySortAndCursor(Builder $query, AlbumListCriteria $criteria, ?AlbumCursor $cursor): void
    {
        if ($criteria->sort === 'updated') {
            if ($cursor !== null) {
                $query->where(function (Builder $after) use ($cursor): void {
                    $after->where('albums.updated_at', '<', $cursor->value)
                        ->orWhere(function (Builder $tie) use ($cursor): void {
                            $tie->where('albums.updated_at', $cursor->value)->where('albums.id', '<', $cursor->id);
                        });
                });
            }
            $query->orderByDesc('albums.updated_at')->orderByDesc('albums.id');

            return;
        }

        $direction = $criteria->sort === 'oldest' ? 'asc' : 'desc';
        if ($cursor !== null) {
            $this->applyDateCursor($query, $cursor, $direction);
        }
        // NULL dates stay at the end in both date directions. The unique id is
        // deliberately ordered in the same direction as the primary date.
        $query->orderByRaw('CASE WHEN albums.starts_on IS NULL THEN 1 ELSE 0 END ASC')
            ->orderBy('albums.starts_on', $direction)
            ->orderBy('albums.id', $direction);
    }

    /** @param Builder<Album> $query */
    private function applyDateCursor(Builder $query, AlbumCursor $cursor, string $direction): void
    {
        $operator = $direction === 'asc' ? '>' : '<';
        $query->where(function (Builder $after) use ($cursor, $operator): void {
            if ($cursor->nullRank === 0) {
                $after->whereNull('albums.starts_on')
                    ->orWhere(function (Builder $dated) use ($cursor, $operator): void {
                        $dated->whereNotNull('albums.starts_on')
                            ->where(function (Builder $position) use ($cursor, $operator): void {
                                $position->whereDate('albums.starts_on', $operator, $cursor->value)
                                    ->orWhere(function (Builder $tie) use ($cursor, $operator): void {
                                        $tie->whereDate('albums.starts_on', $cursor->value)
                                            ->where('albums.id', $operator, $cursor->id);
                                    });
                            });
                    });

                return;
            }
            $after->whereNull('albums.starts_on')->where('albums.id', $operator, $cursor->id);
        });
    }

    private function cursorFor(Album $album, AlbumListCriteria $criteria): AlbumCursor
    {
        if ($criteria->sort === 'updated') {
            return new AlbumCursor(
                $criteria->sort,
                $criteria->fingerprint(),
                0,
                $album->updated_at?->format('Y-m-d H:i:s'),
                $album->id,
            );
        }

        return new AlbumCursor(
            $criteria->sort,
            $criteria->fingerprint(),
            $album->starts_on === null ? 1 : 0,
            $album->starts_on?->format('Y-m-d'),
            $album->id,
        );
    }
}
