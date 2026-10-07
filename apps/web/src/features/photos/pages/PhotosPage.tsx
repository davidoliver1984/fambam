import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useParams } from "react-router";

import {
  ArchiveToolbar,
  Breadcrumbs,
  Button,
  ButtonLink,
  ContextMenu,
  EntityLink,
  PageHeader,
  StatusPanel,
} from "@/components/ui";
import {
  PlusGlyph,
  SearchGlyph,
  SlidersGlyph,
  XGlyph,
  ZoomInGlyph,
} from "@/features/events/components/EventGlyphs";
import { PhotoTileStats } from "@/features/events/components/EventPhotoTile";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";

import { PhotoContextMenu } from "../components/PhotoContextMenu";
import { PhotoPresentationImage } from "../components/PhotoPresentationImage";
import { usePhotosQuery } from "../hooks/usePhotoQueries";
import type {
  Photo,
  PhotoFilters,
  PhotoListCriteria,
  PhotoListSort,
} from "../types/photo";

import "./photos.css";

type Layout = "grid" | "list";
type Sort = "Newest first" | "Oldest first" | "Recently added";
const SORT_OPTIONS: Sort[] = ["Newest first", "Oldest first", "Recently added"];
type FilterKind = "album" | "person" | "location" | "date" | "tag" | "undated";
type FilterChip = {
  key: string;
  label: string;
  type: string;
  kind: FilterKind;
  value: string;
};

function ArchiveIcon({
  kind,
}: {
  kind: "upload" | "grid" | "list" | "check" | "chevron";
}) {
  if (kind === "grid")
    return (
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
      >
        <rect x="3" y="3" width="7" height="7" rx="1" />
        <rect x="14" y="3" width="7" height="7" rx="1" />
        <rect x="3" y="14" width="7" height="7" rx="1" />
        <rect x="14" y="14" width="7" height="7" rx="1" />
      </svg>
    );
  if (kind === "list")
    return (
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
      >
        <path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01" />
      </svg>
    );
  if (kind === "check")
    return (
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="2"
      >
        <path d="m5 12 4 4L19 6" />
      </svg>
    );
  if (kind === "chevron")
    return (
      <svg
        aria-hidden="true"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.8"
      >
        <path d="m6 9 6 6 6-6" />
      </svg>
    );
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="M12 3v12M7 8l5-5 5 5M5 14v6h14v-6" />
    </svg>
  );
}

function chipFilters(chips: FilterChip[]): PhotoFilters {
  const filters: PhotoFilters = {};
  for (const chip of chips) {
    if (chip.kind === "album") filters.without_album = true;
    if (chip.kind === "person") filters.person_id = chip.value;
    if (chip.kind === "location") filters.location = chip.value;
    if (chip.kind === "date") filters.historical_year = chip.value;
    if (chip.kind === "tag") filters.tag = chip.value;
    if (chip.kind === "undated") filters.without_confirmed_date = true;
  }
  return filters;
}

function photoMeta(photo: Photo): string[] {
  const date = photo.historical_date?.value;
  return [
    photo.location_description,
    photo.people.map((item) => item.person.preferred_name).join(", "),
    date === null || date === undefined ? null : date.slice(0, 4),
  ].filter((value): value is string => value !== null && value !== "");
}

function buildSuggestions(
  search: string,
  people: Array<{ id: string; preferred_name: string }>,
): FilterChip[] {
  const normalized = search.trim();
  const suggestions: FilterChip[] = [
    {
      key: "album:without",
      label: "Not in an album",
      type: "Album status",
      kind: "album",
      value: "true",
    },
    {
      key: "date:without",
      label: "Without a confirmed date",
      type: "Date status",
      kind: "undated",
      value: "true",
    },
  ];
  people
    .filter(
      (person) =>
        normalized === "" ||
        person.preferred_name
          .toLocaleLowerCase()
          .includes(normalized.toLocaleLowerCase()),
    )
    .slice(0, normalized === "" ? 2 : 5)
    .forEach((person) => {
      suggestions.push({
        key: `person:${person.id}`,
        label: person.preferred_name,
        type: "Person",
        kind: "person",
        value: person.id,
      });
    });
  if (normalized !== "") {
    if (/^\d{4}$/.test(normalized)) {
      suggestions.push({
        key: `date:${normalized}`,
        label: normalized,
        type: "Historical year",
        kind: "date",
        value: normalized,
      });
    } else {
      suggestions.push(
        {
          key: `location:${normalized}`,
          label: normalized,
          type: "Location",
          kind: "location",
          value: normalized,
        },
        {
          key: `tag:${normalized}`,
          label: normalized,
          type: "Tag",
          kind: "tag",
          value: normalized,
        },
      );
    }
  }
  return suggestions;
}

export function PhotosPage() {
  const { familySlug = "" } = useParams();
  const [search, setSearch] = useState("");
  const [chips, setChips] = useState<FilterChip[]>([]);
  const [sort, setSort] = useState<Sort>("Newest first");
  const [layout, setLayout] = useState<Layout>("grid");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const searchWrap = useRef<HTMLDivElement>(null);
  const loadMoreRef = useRef<HTMLDivElement>(null);
  const nextPageRequestRef = useRef(false);
  const filters = useMemo(() => chipFilters(chips), [chips]);
  const criteria = useMemo<PhotoListCriteria>(() => {
    const next: PhotoListCriteria = {
      ...filters,
      sort: (
        {
          "Newest first": "newest",
          "Oldest first": "oldest",
          "Recently added": "recently_added",
        } satisfies Record<Sort, PhotoListSort>
      )[sort],
    };
    const normalizedSearch = search.trim();
    if (normalizedSearch !== "") next.q = normalizedSearch;
    return next;
  }, [filters, search, sort]);
  const photos = usePhotosQuery(familySlug, criteria);
  const people = usePeopleQuery(familySlug, suggestionsOpen || filtersOpen);
  const suggestions = useMemo(
    () => buildSuggestions(search, people.data ?? []),
    [people.data, search],
  );
  const observerAvailable = typeof IntersectionObserver !== "undefined";
  const {
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    isFetchNextPageError,
  } = photos;
  const visible = useMemo(() => {
    const seen = new Set<string>();
    return (photos.data ?? []).filter((photo) => {
      if (seen.has(photo.id)) return false;
      seen.add(photo.id);
      return true;
    });
  }, [photos.data]);

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (!searchWrap.current?.contains(event.target as Node))
        setSuggestionsOpen(false);
    };
    document.addEventListener("mousedown", close);
    return () => {
      document.removeEventListener("mousedown", close);
    };
  }, []);

  useEffect(() => {
    const target = loadMoreRef.current;
    if (
      target === null ||
      !observerAvailable ||
      photos.isPending ||
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
  }, [
    fetchNextPage,
    hasNextPage,
    isFetchingNextPage,
    observerAvailable,
    photos.isPending,
  ]);

  const addChip = (chip: FilterChip) => {
    setChips((current) => [
      ...current.filter((item) => item.kind !== chip.kind),
      chip,
    ]);
    setSearch("");
    setSuggestionsOpen(false);
  };
  const clear = () => {
    setSearch("");
    setChips([]);
  };

  return (
    <main className="photos-index" aria-labelledby="photos-title">
      <Breadcrumbs
        items={[
          { label: "Home", to: `/families/${encodeURIComponent(familySlug)}` },
          { label: "Photographs" },
        ]}
      />
      <PageHeader
        eyebrow="Family archive"
        id="photos-title"
        title="Photographs"
        description="Photographs gathered across the family archive."
        actions={
          <ButtonLink
            variant="primary"
            className="photos-index__add"
            to={`/families/${encodeURIComponent(familySlug)}/uploads`}
          >
            <ArchiveIcon kind="upload" />
            Add photos
          </ButtonLink>
        }
      />

      <section className="photos-toolbar" aria-label="Search and filter photos">
        <ArchiveToolbar
          label="Search and filter photos"
          search={
            <div className="photos-toolbar__search" ref={searchWrap}>
              <SearchGlyph />
              <input
                value={search}
                aria-label="Search photographs…"
                placeholder="Search photographs…"
                onFocus={() => {
                  setSuggestionsOpen(true);
                }}
                onChange={(event) => {
                  setSearch(event.target.value);
                  setSuggestionsOpen(true);
                }}
                onKeyDown={(event) => {
                  if (event.key === "Enter") {
                    event.preventDefault();
                    setSuggestionsOpen(false);
                  }
                  if (event.key === "Escape") {
                    setSuggestionsOpen(false);
                  }
                }}
              />
              {search !== "" && (
                <button
                  type="button"
                  aria-label="Clear search"
                  onClick={() => {
                    setSearch("");
                    setSuggestionsOpen(false);
                  }}
                >
                  <XGlyph />
                </button>
              )}
              {suggestionsOpen && (
                <div className="photos-toolbar__suggestions">
                  <p>{search ? "Suggestions" : "Try searching by"}</p>
                  {suggestions.map((suggestion) => (
                    <button
                      type="button"
                      key={suggestion.key}
                      aria-label={`Filter by ${suggestion.type}: ${suggestion.label}`}
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
                      <PlusGlyph />
                    </button>
                  ))}
                </div>
              )}
            </div>
          }
        >
          <div className="photos-toolbar__sort">
            <span>Sort</span>
            <ContextMenu
              label="Sort photographs"
              placement="bottom-end"
              panelClassName="photos-sort-menu"
              trigger={
                <>
                  <b>{sort}</b>
                  <ArchiveIcon kind="chevron" />
                </>
              }
            >
              {SORT_OPTIONS.map((option) => (
                <button
                  type="button"
                  role="menuitemradio"
                  aria-checked={option === sort}
                  key={option}
                  onClick={() => {
                    setSort(option);
                  }}
                >
                  {option}
                  {option === sort && <ArchiveIcon kind="check" />}
                </button>
              ))}
            </ContextMenu>
          </div>
          <button
            type="button"
            className={`photos-toolbar__filters${filtersOpen ? " active" : ""}`}
            aria-expanded={filtersOpen}
            onClick={() => {
              setFiltersOpen((open) => !open);
            }}
          >
            <SlidersGlyph />
            Filters
            {chips.length > 0 && <span>{chips.length}</span>}
          </button>
          <div className="photos-toolbar__views" aria-label="Choose view">
            <button
              type="button"
              className={layout === "grid" ? "active" : ""}
              aria-label="Grid view"
              aria-pressed={layout === "grid"}
              onClick={() => {
                setLayout("grid");
              }}
            >
              <ArchiveIcon kind="grid" />
            </button>
            <button
              type="button"
              className={layout === "list" ? "active" : ""}
              aria-label="List view"
              aria-pressed={layout === "list"}
              onClick={() => {
                setLayout("list");
              }}
            >
              <ArchiveIcon kind="list" />
            </button>
          </div>
        </ArchiveToolbar>
        {(chips.length > 0 || filtersOpen) && (
          <div className="photos-toolbar__lower">
            {chips.map((chip) => (
              <button
                type="button"
                className="photos-toolbar__chip"
                key={chip.key}
                onClick={() => {
                  setChips((current) =>
                    current.filter((item) => item.key !== chip.key),
                  );
                }}
              >
                {chip.label}
                <XGlyph />
              </button>
            ))}
            {chips.length > 1 && (
              <button
                type="button"
                className="photos-toolbar__clear"
                onClick={() => {
                  setChips([]);
                }}
              >
                Clear all
              </button>
            )}
            {filtersOpen && (
              <div className="photos-toolbar__quick">
                <span>Quick filters</span>
                {suggestions.map((suggestion) => {
                  const selected = chips.some(
                    (chip) => chip.key === suggestion.key,
                  );
                  return (
                    <button
                      type="button"
                      key={suggestion.key}
                      className={selected ? "selected" : ""}
                      onClick={() => {
                        if (selected) {
                          setChips((current) =>
                            current.filter(
                              (chip) => chip.key !== suggestion.key,
                            ),
                          );
                        } else {
                          addChip(suggestion);
                        }
                      }}
                    >
                      {suggestion.type}: {suggestion.label}
                      {selected && <ArchiveIcon kind="check" />}
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </section>

      {photos.isPending && (
        <StatusPanel tone="loading" title="Loading photographs…">
          Opening the family archive.
        </StatusPanel>
      )}
      {photos.isError && (
        <StatusPanel
          tone="error"
          title="The photograph archive could not be loaded."
        >
          Try again when the connection is available.
        </StatusPanel>
      )}
      {photos.isSuccess && visible.length === 0 && (
        <div className="photos-empty">
          <SearchGlyph />
          <h2>
            {search.trim() !== "" || chips.length > 0
              ? "No matches found"
              : "No photographs yet"}
          </h2>
          <p>
            {search.trim() !== "" || chips.length > 0
              ? "Try another word or remove a filter."
              : "Add the first photograph to begin this family archive."}
          </p>
          {search.trim() !== "" || chips.length > 0 ? (
            <button type="button" onClick={clear}>
              Clear search and filters
            </button>
          ) : (
            <ButtonLink
              variant="primary"
              to={`/families/${encodeURIComponent(familySlug)}/uploads`}
            >
              Add photos
            </ButtonLink>
          )}
        </div>
      )}
      {photos.isSuccess && visible.length > 0 && (
        <>
          <p className="photos-index__marker">
            Matching photographs <span>{visible.length} shown</span>
          </p>
          <div
            className={`photos-grid${layout === "list" ? " photos-grid--list" : ""}`}
          >
            {visible.map((photo, index) => {
              const title = photo.caption ?? photo.media_upload.client_filename;
              const meta = photoMeta(photo);
              const path = `/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photo.id)}`;
              return (
                <article
                  className={`photos-tile photos-tile--${String(index % 4)}`}
                  key={photo.id}
                >
                  <Link className="photos-tile__image" to={path}>
                    <PhotoPresentationImage
                      familySlug={familySlug}
                      photoId={photo.id}
                      mediaUploadId={photo.media_upload.id}
                      fallbackTransform="card"
                      alt={title}
                    />
                    <span className="photos-tile__hover">
                      <ZoomInGlyph />
                    </span>
                    {photo.album_count === 0 && (
                      <span className="photos-tile__unfiled">
                        Not in an album
                      </span>
                    )}
                  </Link>
                  <PhotoTileStats
                    familySlug={familySlug}
                    photoId={photo.id}
                    albumId={photo.interaction_album_id ?? undefined}
                    summary={{
                      loveCount: photo.love_count,
                      commentCount: photo.comment_count,
                      viewerHasLoved: photo.viewer_has_loved,
                      canInteract: photo.interaction_can_interact,
                    }}
                  />
                  <span className="photos-tile__copy">
                    <EntityLink entity="photo" to={path}>
                      <b>{title}</b>
                    </EntityLink>
                    {meta.length > 0 && <small>{meta.join(" · ")}</small>}
                    {photo.album_count === 0 && <em>Not in an album</em>}
                  </span>
                  <div className="photos-tile__menu">
                    <PhotoContextMenu familySlug={familySlug} photo={photo} />
                  </div>
                </article>
              );
            })}
          </div>
        </>
      )}
      <div
        ref={loadMoreRef}
        className="photos-load-sentinel"
        aria-hidden="true"
      />
      <div className="photos-pagination" aria-live="polite">
        {isFetchingNextPage && <p role="status">Loading more photographs…</p>}
        {hasNextPage && (!observerAvailable || isFetchNextPageError) && (
          <Button
            type="button"
            variant="secondary"
            disabled={isFetchingNextPage}
            onClick={() => {
              void fetchNextPage();
            }}
          >
            Load more photographs
          </Button>
        )}
        {isFetchNextPageError && (
          <p role="alert">More photographs could not be loaded.</p>
        )}
        {!hasNextPage && visible.length > 0 && (
          <p className="photos-results-end">All photographs loaded</p>
        )}
      </div>
    </main>
  );
}
