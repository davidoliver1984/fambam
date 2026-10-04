import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type ReactNode,
  type SyntheticEvent,
} from "react";
import { Link, useParams } from "react-router";

import {
  ActionNotice,
  type ActionNoticeMessage,
  ArchiveCard,
  ArchiveToolbar,
  Breadcrumbs,
  Button,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  PageHeader,
  StatusPanel,
} from "@/components/ui";
import {
  useCollectionsQuery,
  useCreateCollectionMutation,
  usePopulateCollectionMutation,
} from "@/features/collections/hooks/useCollections";
import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import {
  useAlbumExportMutation,
  useAlbumsQuery,
  useDeleteAlbumMutation,
  useUpdateAlbumMutation,
} from "../hooks/useAlbumQueries";
import type {
  Album,
  AlbumListCriteria,
  UpdateAlbumInput,
} from "../types/album";
import "./albums.css";

type AlbumSort = "newest" | "oldest" | "updated";
type AlbumView = "grid" | "list";
type AlbumDialog = "edit" | "collection" | "people" | "tags";
type FilterSuggestion = {
  id: string;
  label: string;
  type: "Event" | "Person" | "Place" | "Date" | "Tag";
  criteria: Pick<
    AlbumListCriteria,
    "location" | "date_from" | "date_to" | "tag_id" | "event_id"
  > & { person_id?: string };
};

const monthFormatter = new Intl.DateTimeFormat("en-GB", {
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

const dateFormatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});

function albumDate(album: Album) {
  return album.starts_on ?? null;
}

function monthLabel(album: Album) {
  const date = albumDate(album);
  return date === null
    ? "Undated albums"
    : monthFormatter.format(new Date(`${date}T00:00:00Z`));
}

function formatAlbumDate(album: Album) {
  const start = album.starts_on;
  if (start === undefined || start === null) return null;
  const formattedStart = dateFormatter.format(new Date(`${start}T00:00:00Z`));
  if (
    album.ends_on === undefined ||
    album.ends_on === null ||
    album.ends_on === start
  )
    return formattedStart;
  return `${formattedStart} – ${dateFormatter.format(new Date(`${album.ends_on}T00:00:00Z`))}`;
}

function albumMeta(album: Album) {
  const count = `${String(album.photo_count)} photo${album.photo_count === 1 ? "" : "s"}`;
  const context =
    album.creator?.name ?? formatAlbumDate(album) ?? album.location;
  return context ? `${count} · ${context}` : count;
}

function uniqueSuggestions(albums: Album[]): FilterSuggestion[] {
  const values: FilterSuggestion[] = [];
  const seen = new Set<string>();
  const add = (suggestion: FilterSuggestion) => {
    if (suggestion.label.trim() !== "" && !seen.has(suggestion.id)) {
      seen.add(suggestion.id);
      values.push(suggestion);
    }
  };
  albums.forEach((album) => {
    if (album.event)
      add({
        id: `event:${album.event.id}`,
        label: album.event.name,
        type: "Event",
        criteria: { event_id: album.event.id },
      });
    (album.people ?? []).forEach((person) => {
      add({
        id: `person:${person.id}`,
        label: person.name,
        type: "Person",
        criteria: { person_id: person.id },
      });
    });
    if (album.location)
      add({
        id: `place:${album.location.toLocaleLowerCase()}`,
        label: album.location,
        type: "Place",
        criteria: { location: album.location },
      });
    if (album.starts_on) {
      const date = new Date(`${album.starts_on}T00:00:00Z`);
      const year = date.getUTCFullYear();
      const month = date.getUTCMonth();
      const lastDay = new Date(Date.UTC(year, month + 1, 0)).getUTCDate();
      add({
        id: `date:${String(year)}-${String(month + 1).padStart(2, "0")}`,
        label: monthLabel(album),
        type: "Date",
        criteria: {
          date_from: `${String(year)}-${String(month + 1).padStart(2, "0")}-01`,
          date_to: `${String(year)}-${String(month + 1).padStart(2, "0")}-${String(lastDay).padStart(2, "0")}`,
        },
      });
    }
    (album.tags ?? []).forEach((tag) => {
      add({
        id: `tag:${tag.id}`,
        label: tag.label,
        type: "Tag",
        criteria: { tag_id: tag.id },
      });
    });
  });
  return values;
}

function MenuIcon({ name }: { name: string }) {
  const paths: Record<string, ReactNode> = {
    open: (
      <>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <path d="M8 10h8M8 14h5" />
      </>
    ),
    search: (
      <>
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-4-4" />
      </>
    ),
    filters: (
      <>
        <path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3" />
        <path d="M1 14h6M9 8h6M17 16h6" />
      </>
    ),
    grid: (
      <>
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </>
    ),
    list: (
      <>
        <path d="M8 6h13M8 12h13M8 18h13" />
        <path d="M3 6h.01M3 12h.01M3 18h.01" />
      </>
    ),
    chevron: <path d="m6 9 6 6 6-6" />,
    sparkles: (
      <>
        <path d="m12 3 1.4 4.1L18 8.5l-4.6 1.4L12 14l-1.4-4.1L6 8.5l4.6-1.4L12 3Z" />
        <path d="m5 15 .8 2.2L8 18l-2.2.8L5 21l-.8-2.2L2 18l2.2-.8L5 15Z" />
      </>
    ),
    lock: (
      <>
        <rect x="5" y="10" width="14" height="11" rx="2" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
      </>
    ),
    edit: (
      <>
        <path d="m4 20 4.5-1 10-10-3.5-3.5-10 10L4 20Z" />
        <path d="m13.5 7 3.5 3.5" />
      </>
    ),
    plus: <path d="M12 5v14M5 12h14" />,
    collection: (
      <>
        <path d="m12 3 1.4 4.1L18 8.5l-4.6 1.4L12 14l-1.4-4.1L6 8.5l4.6-1.4L12 3Z" />
        <path d="m5 15 .8 2.2L8 18l-2.2.8L5 21l-.8-2.2L2 18l2.2-.8L5 15Z" />
      </>
    ),
    link: (
      <>
        <path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.2 1.2" />
        <path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.2-1.2" />
      </>
    ),
    download: (
      <>
        <path d="M12 3v12M7 10l5 5 5-5" />
        <path d="M5 21h14" />
      </>
    ),
    people: (
      <>
        <circle cx="9" cy="8" r="3" />
        <path d="M3 20c.5-4 2.5-6 6-6s5.5 2 6 6" />
        <path d="M16 5.5a3 3 0 0 1 0 5.5M17 14c2.3.6 3.6 2.6 4 6" />
      </>
    ),
    tag: (
      <>
        <path d="M20 13 13 20 4 11V4h7l9 9Z" />
        <circle cx="8.5" cy="8.5" r="1" />
      </>
    ),
    trash: (
      <>
        <path d="M4 7h16M9 7V4h6v3M7 7l1 14h8l1-14" />
        <path d="M10 11v6M14 11v6" />
      </>
    ),
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths[name]}
    </svg>
  );
}

export function AlbumsPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const canCreate = ["owner", "administrator", "member"].includes(
    family.data?.role ?? "",
  );
  const [query, setQuery] = useState("");
  const [chips, setChips] = useState<FilterSuggestion[]>([]);
  const [sort, setSort] = useState<AlbumSort>("newest");
  const [view, setView] = useState<AlbumView>("grid");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [selectedAlbum, setSelectedAlbum] = useState<Album | null>(null);
  const [dialog, setDialog] = useState<AlbumDialog | null>(null);
  const [deleteTarget, setDeleteTarget] = useState<Album | null>(null);
  const [notice, setNotice] = useState<ActionNoticeMessage | null>(null);
  const announce = (title: string) => {
    setNotice({ title });
  };
  const searchWrap = useRef<HTMLDivElement>(null);
  const loadMoreRef = useRef<HTMLDivElement>(null);
  const nextPageRequestRef = useRef(false);
  const criteria = useMemo<AlbumListCriteria>(() => {
    const next: AlbumListCriteria = { sort };
    const normalizedQuery = query.trim();
    if (normalizedQuery !== "") next.q = normalizedQuery;
    const personIds: string[] = [];
    chips.forEach((chip) => {
      const { person_id: personId, ...filter } = chip.criteria;
      Object.assign(next, filter);
      if (personId !== undefined) personIds.push(personId);
    });
    if (personIds.length > 0) next.person_ids = personIds.toSorted();
    return next;
  }, [chips, query, sort]);
  const albums = useAlbumsQuery(familySlug, criteria);
  const observerAvailable = typeof IntersectionObserver !== "undefined";
  const {
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isFetchNextPageError,
  } = albums;

  const suggestions = useMemo(
    () => uniqueSuggestions(albums.data ?? []),
    [albums.data],
  );
  const matchingSuggestions = suggestions.filter((suggestion) => {
    const normalized = query.trim().toLocaleLowerCase();
    return (
      normalized === "" ||
      `${suggestion.label} ${suggestion.type}`
        .toLocaleLowerCase()
        .includes(normalized)
    );
  });
  const visibleAlbums = useMemo(() => {
    const seen = new Set<string>();
    return (albums.data ?? []).filter((album) => {
      if (seen.has(album.id)) return false;
      seen.add(album.id);
      return true;
    });
  }, [albums.data]);
  const monthGroups = useMemo(() => {
    const grouped: Array<[string, Album[]]> = [];
    visibleAlbums.forEach((album) => {
      const label = monthLabel(album);
      const current = grouped.at(-1);
      if (current?.[0] === label) current[1].push(album);
      else grouped.push([label, [album]]);
    });
    return grouped;
  }, [visibleAlbums]);

  useEffect(() => {
    const dismiss = (event: MouseEvent) => {
      if (!searchWrap.current?.contains(event.target as Node))
        setSuggestionsOpen(false);
    };
    document.addEventListener("mousedown", dismiss);
    return () => {
      document.removeEventListener("mousedown", dismiss);
    };
  }, []);

  useEffect(() => {
    const target = loadMoreRef.current;
    if (
      target === null ||
      !observerAvailable ||
      !hasNextPage ||
      isFetchingNextPage
    ) {
      return;
    }
    const observer = new IntersectionObserver(
      (entries) => {
        if (
          entries.some((entry) => entry.isIntersecting) &&
          !nextPageRequestRef.current
        ) {
          nextPageRequestRef.current = true;
          void fetchNextPage().finally(() => {
            nextPageRequestRef.current = false;
          });
        }
      },
      { rootMargin: "400px 0px" },
    );
    observer.observe(target);
    return () => {
      observer.disconnect();
    };
  }, [fetchNextPage, hasNextPage, isFetchingNextPage, observerAvailable]);

  const addChip = (suggestion: FilterSuggestion) => {
    setChips((current) => {
      if (current.some((chip) => chip.id === suggestion.id)) return current;
      if (suggestion.type === "Person") return [...current, suggestion];
      return [
        ...current.filter((chip) => chip.type !== suggestion.type),
        suggestion,
      ];
    });
    setQuery("");
    setSuggestionsOpen(false);
  };
  const openDialog = (album: Album, nextDialog: AlbumDialog) => {
    setSelectedAlbum(album);
    setDialog(nextDialog);
  };
  const closeDialog = () => {
    setDialog(null);
    setSelectedAlbum(null);
  };

  return (
    <main className="album-index" aria-labelledby="albums-title">
      <Breadcrumbs
        items={[
          { label: "Home", to: `/families/${encodeURIComponent(familySlug)}` },
          { label: "Albums" },
        ]}
      />
      <PageHeader
        eyebrow="Family archive"
        title="Albums"
        id="albums-title"
        description="Photographs gathered into the moments they belong to."
        actions={
          canCreate ? (
            <ButtonLink
              variant="primary"
              to={`/families/${encodeURIComponent(familySlug)}/albums/new`}
            >
              <span aria-hidden="true">＋</span> Create album
            </ButtonLink>
          ) : undefined
        }
      />

      <ArchiveToolbar
        label="Search and filter albums"
        search={
          <div className="album-search" ref={searchWrap}>
            <label className="ui-visually-hidden" htmlFor="album-search">
              Search albums
            </label>
            <MenuIcon name="search" />
            <input
              id="album-search"
              type="search"
              placeholder="Search albums…"
              value={query}
              onFocus={() => {
                setSuggestionsOpen(true);
              }}
              onChange={(event) => {
                setQuery(event.target.value);
                setSuggestionsOpen(true);
              }}
              onKeyDown={(event) => {
                if (event.key === "Enter" || event.key === "Escape") {
                  setSuggestionsOpen(false);
                }
              }}
            />
            {query !== "" && (
              <button
                type="button"
                aria-label="Clear search"
                onClick={() => {
                  setQuery("");
                }}
              >
                ×
              </button>
            )}
            {suggestionsOpen && matchingSuggestions.length > 0 && (
              <div className="album-suggestions" role="listbox">
                <p>{query ? "Suggestions" : "Try searching by"}</p>
                {matchingSuggestions.map((suggestion) => (
                  <button
                    type="button"
                    key={`${suggestion.type}-${suggestion.label}`}
                    onMouseDown={(event) => {
                      event.preventDefault();
                    }}
                    onClick={() => {
                      addChip(suggestion);
                    }}
                  >
                    <span>
                      <b>{suggestion.label}</b>
                      <small>{suggestion.type}</small>
                    </span>
                    <span aria-hidden="true">＋</span>
                  </button>
                ))}
              </div>
            )}
          </div>
        }
      >
        <label className="album-sort" htmlFor="album-sort">
          <span>Sort</span>
          <select
            id="album-sort"
            value={sort}
            onChange={(event) => {
              setSort(event.target.value as AlbumSort);
            }}
          >
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="updated">Recently updated</option>
          </select>
          <MenuIcon name="chevron" />
        </label>
        <Button
          className={`album-filter-toggle${filtersOpen ? " is-active" : ""}`}
          variant="secondary"
          aria-expanded={filtersOpen}
          onClick={() => {
            setFiltersOpen((current) => !current);
          }}
        >
          <MenuIcon name="filters" /> Filters
          {chips.length > 0 && (
            <span className="album-filter-count">{chips.length}</span>
          )}
        </Button>
        <div className="album-view-toggle" aria-label="Choose view">
          <Button
            variant={view === "grid" ? "primary" : "secondary"}
            aria-pressed={view === "grid"}
            aria-label="Grid view"
            onClick={() => {
              setView("grid");
            }}
          >
            <MenuIcon name="grid" />
          </Button>
          <Button
            variant={view === "list" ? "primary" : "secondary"}
            aria-pressed={view === "list"}
            aria-label="List view"
            onClick={() => {
              setView("list");
            }}
          >
            <MenuIcon name="list" />
          </Button>
        </div>
      </ArchiveToolbar>

      {(chips.length > 0 || filtersOpen) && (
        <div className="album-filter-panel">
          {chips.map((chip) => (
            <button
              type="button"
              className="album-filter-chip"
              key={chip.id}
              onClick={() => {
                setChips((current) =>
                  current.filter((item) => item.id !== chip.id),
                );
              }}
            >
              {chip.label} <span aria-hidden="true">×</span>
            </button>
          ))}
          {chips.length > 1 && (
            <button
              type="button"
              className="album-clear-filters"
              onClick={() => {
                setChips([]);
              }}
            >
              Clear all
            </button>
          )}
          {filtersOpen && (
            <div className="album-quick-filters">
              <span>Quick filters</span>
              {suggestions.map((suggestion) => (
                <button
                  type="button"
                  className={
                    chips.some((chip) => chip.id === suggestion.id)
                      ? "is-selected"
                      : ""
                  }
                  key={`${suggestion.type}-${suggestion.label}`}
                  onClick={() => {
                    if (chips.some((chip) => chip.id === suggestion.id)) {
                      setChips((current) =>
                        current.filter((item) => item.id !== suggestion.id),
                      );
                    } else {
                      addChip(suggestion);
                    }
                  }}
                >
                  {suggestion.type}: {suggestion.label}
                  {chips.some((chip) => chip.id === suggestion.id) && (
                    <span aria-hidden="true"> ✓</span>
                  )}
                </button>
              ))}
            </div>
          )}
        </div>
      )}

      {albums.isPending ? (
        <StatusPanel tone="loading" title="Loading albums…" />
      ) : albums.isError && albums.data === undefined ? (
        <StatusPanel tone="error" title="Albums could not be loaded">
          <p>Please try again.</p>
        </StatusPanel>
      ) : visibleAlbums.length === 0 &&
        (query.trim() !== "" || chips.length > 0) ? (
        <div className="album-empty">
          <MenuIcon name="search" />
          <h2>No matches found</h2>
          <p>Try another word or remove a filter.</p>
          <Button
            variant="secondary"
            onClick={() => {
              setQuery("");
              setChips([]);
            }}
          >
            Clear search and filters
          </Button>
        </div>
      ) : visibleAlbums.length === 0 ? (
        <AlbumEmpty canCreate={canCreate} familySlug={familySlug} />
      ) : (
        monthGroups.map(([month, items], groupIndex) => (
          <section
            className="album-month"
            aria-labelledby={`month-${month.replaceAll(" ", "-")}-${String(groupIndex)}`}
            key={`${month}-${String(groupIndex)}`}
          >
            <h2
              id={`month-${month.replaceAll(" ", "-")}-${String(groupIndex)}`}
            >
              {month}
            </h2>
            <div className={`album-card-grid album-card-grid--${view}`}>
              {items.map((album) => {
                const to = `/families/${encodeURIComponent(familySlug)}/albums/${album.id}`;
                return (
                  <ArchiveCard
                    key={album.id}
                    entity="album"
                    className="album-index-card"
                    title={album.name}
                    to={to}
                    stretchLink
                    eyebrow={
                      album.is_new ? (
                        <span className="album-new-badge">New</span>
                      ) : undefined
                    }
                    media={
                      album.cover ? (
                        <PhotoPresentationImage
                          familySlug={familySlug}
                          photoId={album.cover.photo_id}
                          mediaUploadId={album.cover.media_upload_id}
                          fallbackTransform="card"
                          alt=""
                          style={{
                            objectPosition: `${String(album.cover.focal_x * 100)}% ${String(album.cover.focal_y * 100)}%`,
                          }}
                        />
                      ) : (
                        <div
                          className="album-cover-placeholder"
                          aria-label="No cover photo"
                        >
                          <MenuIcon name="open" />
                          <b>No cover photo</b>
                          <span>Add one whenever this Album is ready.</span>
                        </div>
                      )
                    }
                    meta={albumMeta(album)}
                    actions={
                      <AlbumMenu
                        album={album}
                        familySlug={familySlug}
                        to={to}
                        onOpenDialog={openDialog}
                        onDelete={setDeleteTarget}
                        onAnnouncement={announce}
                      />
                    }
                  />
                );
              })}
            </div>
          </section>
        ))
      )}

      <div
        ref={loadMoreRef}
        className="album-load-sentinel"
        aria-hidden="true"
      />
      <div className="album-pagination" aria-live="polite">
        {isFetchingNextPage && <p role="status">Loading more albums…</p>}
        {hasNextPage && (!observerAvailable || isFetchNextPageError) && (
          <Button
            type="button"
            variant="secondary"
            onClick={() => {
              void fetchNextPage();
            }}
            disabled={isFetchingNextPage}
          >
            Load more albums
          </Button>
        )}
        {isFetchNextPageError && (
          <p role="alert">More albums could not be loaded.</p>
        )}
        {!hasNextPage && visibleAlbums.length > 0 && (
          <p className="album-results-end">All albums loaded</p>
        )}
      </div>

      {notice && (
        <ActionNotice
          title={notice.title}
          description={notice.description}
          onDismiss={() => {
            setNotice(null);
          }}
        />
      )}
      {selectedAlbum && dialog && (
        <AlbumActionDialog
          key={`${selectedAlbum.id}-${dialog}`}
          album={selectedAlbum}
          dialog={dialog}
          familySlug={familySlug}
          onClose={closeDialog}
          onAnnouncement={announce}
        />
      )}
      {deleteTarget && (
        <DeleteAlbumDialog
          key={deleteTarget.id}
          album={deleteTarget}
          familySlug={familySlug}
          onClose={() => {
            setDeleteTarget(null);
          }}
          onAnnouncement={announce}
        />
      )}
    </main>
  );
}

function AlbumEmpty({
  canCreate,
  familySlug,
}: {
  canCreate: boolean;
  familySlug: string;
}) {
  return (
    <div className="album-empty">
      <MenuIcon name="open" />
      <h2>No albums yet</h2>
      <p>Gather the first photographs into a family Album.</p>
      {canCreate && (
        <ButtonLink
          variant="primary"
          to={`/families/${encodeURIComponent(familySlug)}/albums/new`}
        >
          Create album
        </ButtonLink>
      )}
    </div>
  );
}

function AlbumMenu({
  album,
  familySlug,
  to,
  onOpenDialog,
  onDelete,
  onAnnouncement,
}: {
  album: Album;
  familySlug: string;
  to: string;
  onOpenDialog: (album: Album, dialog: AlbumDialog) => void;
  onDelete: (album: Album) => void;
  onAnnouncement: (message: string) => void;
}) {
  const exportAlbum = useAlbumExportMutation(familySlug, album.id);
  const item = (name: string, label: string, action: () => void) => (
    <button type="button" onClick={action}>
      <MenuIcon name={name} /> {label}
    </button>
  );
  return (
    <ContextMenu label={`Album options for ${album.name}`}>
      <Link to={to}>
        <MenuIcon name="open" /> Open album
      </Link>
      {album.permissions.can_manage &&
        item("edit", "Edit album", () => {
          onOpenDialog(album, "edit");
        })}
      {album.permissions.can_contribute && (
        <Link to={`${to}/uploads`}>
          <MenuIcon name="plus" /> Add photos
        </Link>
      )}
      {item("collection", "Add photos to collection…", () => {
        onOpenDialog(album, "collection");
      })}
      {item("link", "Copy Fambam link", () => {
        const url = new URL(to, window.location.origin).toString();
        void navigator.clipboard.writeText(url);
        onAnnouncement("Fambam link copied");
      })}
      {item("download", "Download / Export album", () => {
        exportAlbum.mutate(undefined, {
          onSuccess: () => {
            onAnnouncement("Album export started");
          },
          onError: () => {
            onAnnouncement("Album export could not be started");
          },
        });
      })}
      {album.permissions.can_manage &&
        item("people", "Manage people", () => {
          onOpenDialog(album, "people");
        })}
      {album.permissions.can_manage &&
        item("tag", "Manage tags", () => {
          onOpenDialog(album, "tags");
        })}
      {(album.permissions.can_delete ?? album.permissions.can_manage) && (
        <hr className="album-menu-separator" />
      )}
      {(album.permissions.can_delete ?? album.permissions.can_manage) && (
        <button
          type="button"
          className="album-menu-danger"
          onClick={() => {
            onDelete(album);
          }}
        >
          <MenuIcon name="trash" /> Delete album
        </button>
      )}
    </ContextMenu>
  );
}

function AlbumActionDialog({
  album,
  dialog,
  familySlug,
  onClose,
  onAnnouncement,
}: {
  album: Album;
  dialog: AlbumDialog;
  familySlug: string;
  onClose: () => void;
  onAnnouncement: (message: string) => void;
}) {
  const albumId = album.id;
  const update = useUpdateAlbumMutation(familySlug, albumId);
  const people = usePeopleQuery(familySlug, dialog === "people");
  const collections = useCollectionsQuery(familySlug, dialog === "collection");
  const createCollection = useCreateCollectionMutation(familySlug);
  const populateCollection = usePopulateCollectionMutation(familySlug, {
    type: "album",
    id: albumId,
  });
  const [name, setName] = useState(album.name);
  const [startsOn, setStartsOn] = useState(album.starts_on ?? "");
  const [endsOn, setEndsOn] = useState(album.ends_on ?? "");
  const [location, setLocation] = useState(album.location ?? "");
  const [tags, setTags] = useState(
    (album.tags ?? []).map((tag) => tag.label).join(", "),
  );
  const [personIds, setPersonIds] = useState<string[]>(
    (album.people ?? []).map((person) => person.id),
  );
  const [collectionId, setCollectionId] = useState("");
  const [createNewCollection, setCreateNewCollection] = useState(false);
  const [newCollectionName, setNewCollectionName] = useState(album.name);
  const [formError, setFormError] = useState("");
  const effectiveCollectionId = collectionId || collections.data?.[0]?.id || "";
  const pending =
    update.isPending ||
    createCollection.isPending ||
    populateCollection.isPending;
  const saveUpdate = (input: UpdateAlbumInput, success: string) => {
    setFormError("");
    update.mutate(input, {
      onSuccess: () => {
        onAnnouncement(success);
        onClose();
      },
      onError: () => {
        setFormError("The Album could not be updated.");
      },
    });
  };
  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (dialog === "edit") {
      saveUpdate(
        {
          name: name.trim(),
          starts_on: startsOn || null,
          ends_on: endsOn || null,
          location: location.trim() || null,
        },
        "Album updated",
      );
    } else if (dialog === "tags") {
      saveUpdate(
        {
          tags: tags
            .split(",")
            .map((tag) => tag.trim())
            .filter(Boolean),
        },
        "Album tags updated",
      );
    } else if (dialog === "people") {
      saveUpdate({ person_ids: personIds }, "Album people updated");
    } else {
      setFormError("");
      void (async () => {
        try {
          const destination = createNewCollection
            ? await createCollection.mutateAsync({
                name: newCollectionName.trim(),
                description: null,
              })
            : collections.data?.find(
                (item) => item.id === effectiveCollectionId,
              );
          if (!destination) throw new Error("A collection is required");
          await populateCollection.mutateAsync(destination.id);
          onAnnouncement(`Photos added to ${destination.name}`);
          onClose();
        } catch {
          setFormError("The Photos could not be added to the Collection.");
        }
      })();
    }
  };

  const titles: Record<AlbumDialog, string> = {
    edit: "Edit album",
    collection: "Add photos to collection",
    people: "Manage people",
    tags: "Manage tags",
  };
  return (
    <Dialog
      open
      title={titles[dialog]}
      eyebrow={dialog === "collection" ? "Personal working set" : album.name}
      description={
        dialog === "collection"
          ? `Add the current Photos from ${album.name}. Duplicate Photo identities will only be added once.`
          : undefined
      }
      pending={pending}
      onClose={onClose}
      className="album-action-dialog"
    >
      <form className="album-dialog-form" onSubmit={submit}>
        {dialog === "collection" && (
          <div className="album-collection-source-summary">
            <MenuIcon name="sparkles" />
            <span>
              <b>{album.name}</b>
              <small>A snapshot of the current source Photos</small>
            </span>
          </div>
        )}
        {dialog === "edit" && (
          <>
            <label htmlFor="album-edit-name">Name</label>
            <input
              id="album-edit-name"
              value={name}
              onChange={(event) => {
                setName(event.target.value);
              }}
              required
              data-autofocus
            />
            <div className="album-dialog-columns">
              <label>
                Start date
                <input
                  aria-label="Start date"
                  type="date"
                  value={startsOn}
                  onChange={(event) => {
                    setStartsOn(event.target.value);
                  }}
                />
              </label>
              <label>
                End date
                <input
                  aria-label="End date"
                  type="date"
                  value={endsOn}
                  onChange={(event) => {
                    setEndsOn(event.target.value);
                  }}
                />
              </label>
            </div>
            <label htmlFor="album-edit-location">Location</label>
            <input
              id="album-edit-location"
              value={location}
              onChange={(event) => {
                setLocation(event.target.value);
              }}
            />
          </>
        )}
        {dialog === "tags" && (
          <>
            <label htmlFor="album-tags">Tags</label>
            <input
              id="album-tags"
              value={tags}
              onChange={(event) => {
                setTags(event.target.value);
              }}
              placeholder="Holiday, Seaside"
              data-autofocus
            />
            <p className="album-dialog-help">Separate tags with commas.</p>
          </>
        )}
        {dialog === "people" && (
          <fieldset className="album-people-picker">
            <legend>People in this album</legend>
            {people.isPending ? (
              <p role="status">Loading people…</p>
            ) : people.isError ? (
              <p role="alert">People could not be loaded.</p>
            ) : people.data.length === 0 ? (
              <p>No People are available.</p>
            ) : (
              people.data.map((person) => (
                <label key={person.id}>
                  <input
                    type="checkbox"
                    checked={personIds.includes(person.id)}
                    onChange={() => {
                      setPersonIds((current) =>
                        current.includes(person.id)
                          ? current.filter((id) => id !== person.id)
                          : [...current, person.id],
                      );
                    }}
                  />
                  {person.preferred_name}
                </label>
              ))
            )}
          </fieldset>
        )}
        {dialog === "collection" && (
          <div className="album-collection-picker">
            <label className={!createNewCollection ? "is-selected" : ""}>
              <input
                type="radio"
                name="collection-mode"
                checked={!createNewCollection}
                onChange={() => {
                  setCreateNewCollection(false);
                }}
              />
              <span>
                <b>Add to existing collection</b>
                <select
                  aria-label="Choose an existing collection"
                  value={effectiveCollectionId}
                  disabled={collections.isPending || collections.isError}
                  onChange={(event) => {
                    setCollectionId(event.target.value);
                    setCreateNewCollection(false);
                  }}
                >
                  {collections.isPending && (
                    <option>Loading collections…</option>
                  )}
                  {collections.isError && (
                    <option>Collections could not be loaded</option>
                  )}
                  {(collections.data ?? []).map((collection) => (
                    <option key={collection.id} value={collection.id}>
                      {collection.name}
                    </option>
                  ))}
                </select>
              </span>
            </label>
            <label className={createNewCollection ? "is-selected" : ""}>
              <input
                type="radio"
                name="collection-mode"
                checked={createNewCollection}
                onChange={() => {
                  setCreateNewCollection(true);
                }}
              />
              <span>
                <b>Create new collection</b>
                <input
                  aria-label="New collection name"
                  value={newCollectionName}
                  onChange={(event) => {
                    setNewCollectionName(event.target.value);
                    setCreateNewCollection(true);
                  }}
                />
              </span>
            </label>
          </div>
        )}
        {dialog === "collection" && (
          <div className="album-private-note">
            <MenuIcon name="lock" />
            <span>
              <b>Private to you</b>
              <small>Collections are not shared with the Family Space.</small>
            </span>
          </div>
        )}
        {formError && <p role="alert">{formError}</p>}
        <div className="ui-inline-actions album-dialog-actions">
          <Button
            type="button"
            variant="secondary"
            disabled={pending}
            onClick={onClose}
          >
            Cancel
          </Button>
          <Button
            type="submit"
            variant="primary"
            disabled={
              pending ||
              (dialog === "collection" &&
                (collections.isPending ||
                  collections.isError ||
                  (createNewCollection
                    ? !newCollectionName.trim()
                    : !effectiveCollectionId)))
            }
          >
            {pending
              ? "Saving…"
              : dialog === "collection"
                ? "Add photos"
                : "Save changes"}
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function DeleteAlbumDialog({
  album,
  familySlug,
  onClose,
  onAnnouncement,
}: {
  album: Album;
  familySlug: string;
  onClose: () => void;
  onAnnouncement: (message: string) => void;
}) {
  const remove = useDeleteAlbumMutation(familySlug, album.id);
  const [error, setError] = useState(false);
  return (
    <ConfirmDialog
      open
      title={`Delete “${album.name}”?`}
      confirmLabel={remove.isPending ? "Deleting…" : "Delete album"}
      destructive
      pending={remove.isPending}
      onCancel={onClose}
      onConfirm={() => {
        setError(false);
        remove.mutate(undefined, {
          onSuccess: () => {
            onAnnouncement("Album deleted");
            onClose();
          },
          onError: () => {
            setError(true);
          },
        });
      }}
    >
      <p>
        This Album will be removed. Its Photos remain safely in the Family Space
        unless they are deleted separately.
      </p>
      {error && <p role="alert">The Album could not be deleted.</p>}
    </ConfirmDialog>
  );
}
