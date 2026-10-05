import {
  useCallback,
  useEffect,
  useId,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
  type ReactNode,
  type SyntheticEvent,
} from "react";
import { Link, useParams } from "react-router";

import {
  Breadcrumbs,
  Button,
  Dialog,
  PageHeader,
  StatusPanel,
} from "@/components/ui";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import {
  useCollectionsQuery,
  useCreateCollectionMutation,
} from "../hooks/useCollections";
import type {
  FamilyCollection,
  CollectionListCriteria,
  CollectionPurpose,
} from "../types/collection";
import "./collections.css";

type CollectionSort = "updated" | "name";
type CollectionView = "grid" | "list";
type CollectionFilter = "william" | CollectionPurpose;
type IconName =
  | "check"
  | "chevron"
  | "filters"
  | "grid"
  | "list"
  | "lock"
  | "plus"
  | "search"
  | "sparkles"
  | "x";

function CollectionIcon({ name }: { name: IconName }) {
  const paths: Record<IconName, ReactNode> = {
    check: <path d="m5 12 4 4L19 6" />,
    chevron: <path d="m6 9 6 6 6-6" />,
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
    lock: (
      <>
        <rect x="5" y="10" width="14" height="11" rx="2" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
      </>
    ),
    plus: <path d="M12 5v14M5 12h14" />,
    search: (
      <>
        <circle cx="11" cy="11" r="7" />
        <path d="m20 20-4-4" />
      </>
    ),
    sparkles: (
      <>
        <path d="m12 3 1.4 4.1L18 8.5l-4.6 1.4L12 14l-1.4-4.1L6 8.5l4.6-1.4L12 3Z" />
        <path d="m5 15 .8 2.2L8 18l-2.2.8L5 21l-.8-2.2L2 18l2.2-.8L5 15Z" />
      </>
    ),
    x: <path d="M18 6 6 18M6 6l12 12" />,
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

const WILLIAM_COLLECTION_NAME = "William’s 50th birthday";
const filterOptions: Array<{
  key: CollectionFilter;
  label: string;
  type: "Collection" | "Purpose";
}> = [
  { key: "william", label: WILLIAM_COLLECTION_NAME, type: "Collection" },
  { key: "prints", label: "Prints", type: "Purpose" },
  { key: "calendar", label: "Calendar", type: "Purpose" },
];

function filterLabel(key: CollectionFilter) {
  const option = filterOptions.find((item) => item.key === key);
  return option === undefined ? key : `${option.type}: ${option.label}`;
}

function CollectionCard({
  base,
  familySlug,
  item,
}: {
  base: string;
  familySlug: string;
  item: FamilyCollection;
}) {
  return (
    <article className="collections-card">
      <Link
        className="collections-card-link"
        to={`${base}/collections/${encodeURIComponent(item.id)}`}
      >
        <span className="collections-cover-stack">
          {item.preview_photo === null ? (
            <span className="collections-cover-placeholder">
              <CollectionIcon name="sparkles" />
            </span>
          ) : (
            <PhotoPresentationImage
              familySlug={familySlug}
              photoId={item.preview_photo.photo_id}
              mediaUploadId={item.preview_photo.media_upload_id}
              fallbackTransform="thumbnail"
              alt=""
            />
          )}
          <i />
          <i />
        </span>
        <span className="collections-card-copy">
          <b>{item.name}</b>
          <small>{item.description ?? ""}</small>
          <em>{item.photo_count} Photos · Private</em>
        </span>
      </Link>
    </article>
  );
}

export function CollectionsPage() {
  const { familySlug = "" } = useParams();
  const [query, setQuery] = useState("");
  const [serverQuery, setServerQuery] = useState("");
  const [selectedFilters, setSelectedFilters] = useState<CollectionFilter[]>(
    [],
  );
  const [sort, setSort] = useState<CollectionSort>("updated");
  const [view, setView] = useState<CollectionView>("grid");
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [suggestionsOpen, setSuggestionsOpen] = useState(false);
  const [williamCollectionId, setWilliamCollectionId] = useState<string | null>(
    null,
  );
  const [createOpen, setCreateOpen] = useState(false);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const searchWrap = useRef<HTMLDivElement>(null);
  const searchInput = useRef<HTMLInputElement>(null);
  const suggestionListId = useId();
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const criteria = useMemo<CollectionListCriteria>(() => {
    const purposes = selectedFilters.filter(
      (filter): filter is CollectionPurpose => filter !== "william",
    );
    return {
      ...(serverQuery === "" ? {} : { q: serverQuery }),
      sort,
      ...(selectedFilters.includes("william") && williamCollectionId !== null
        ? { collection_id: williamCollectionId }
        : {}),
      ...(purposes.length === 0 ? {} : { purpose: purposes }),
    };
  }, [selectedFilters, serverQuery, sort, williamCollectionId]);
  const collections = useCollectionsQuery(familySlug, true, criteria);
  const create = useCreateCollectionMutation(familySlug);
  const createPending = create.isPending;
  const resetCreate = create.reset;

  useEffect(() => {
    const closeSuggestions = (event: MouseEvent) => {
      if (!searchWrap.current?.contains(event.target as Node)) {
        setSuggestionsOpen(false);
      }
    };
    document.addEventListener("mousedown", closeSuggestions);
    return () => {
      document.removeEventListener("mousedown", closeSuggestions);
    };
  }, []);

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setServerQuery(query.trim());
    }, 250);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [query]);

  useEffect(() => {
    if (williamCollectionId !== null) return;
    const match = collections.data?.find(
      (item) => item.name === WILLIAM_COLLECTION_NAME,
    );
    if (match === undefined) return;
    const timeout = window.setTimeout(() => {
      setWilliamCollectionId(match.id);
    }, 0);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [collections.data, williamCollectionId]);

  const suggestions = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase();
    return filterOptions.filter((item) =>
      normalized === ""
        ? true
        : `${item.label} ${item.type}`.toLocaleLowerCase().includes(normalized),
    );
  }, [query]);

  const closeCreate = useCallback(() => {
    if (createPending) return;
    resetCreate();
    setCreateOpen(false);
  }, [createPending, resetCreate]);

  if (collections.isPending) {
    return <StatusPanel tone="loading" title="Loading Collections…" />;
  }
  if (collections.isError) {
    return (
      <StatusPanel tone="error" title="Collections could not be loaded">
        <p>Please try again.</p>
      </StatusPanel>
    );
  }

  const addFilter = (filter: CollectionFilter) => {
    setSelectedFilters((current) =>
      current.includes(filter) ? current : [...current, filter],
    );
    setQuery("");
    setServerQuery("");
    setSuggestionsOpen(false);
  };
  const toggleFilter = (filter: CollectionFilter) => {
    setSelectedFilters((current) =>
      current.includes(filter)
        ? current.filter((item) => item !== filter)
        : [...current, filter],
    );
  };
  const clearSearchAndFilters = () => {
    setQuery("");
    setServerQuery("");
    setSelectedFilters([]);
  };
  const handleSearchKeys = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Escape") {
      setSuggestionsOpen(false);
      return;
    }
    if (event.key === "ArrowDown" && suggestionsOpen) {
      event.preventDefault();
      searchWrap.current
        ?.querySelector<HTMLButtonElement>(".collections-suggestion")
        ?.focus();
    }
  };
  const handleSuggestionKeys = (event: KeyboardEvent<HTMLButtonElement>) => {
    const options = [
      ...(searchWrap.current?.querySelectorAll<HTMLButtonElement>(
        ".collections-suggestion",
      ) ?? []),
    ];
    const index = options.indexOf(event.currentTarget);
    if (event.key === "ArrowDown" && index < options.length - 1) {
      event.preventDefault();
      options[index + 1]?.focus();
    }
    if (event.key === "ArrowUp") {
      event.preventDefault();
      if (index === 0) searchInput.current?.focus();
      else options[index - 1]?.focus();
    }
    if (event.key === "Escape") {
      event.preventDefault();
      setSuggestionsOpen(false);
      searchInput.current?.focus();
    }
  };
  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), description: description.trim() || null },
      {
        onSuccess: () => {
          setName("");
          setDescription("");
          setCreateOpen(false);
        },
      },
    );
  };
  return (
    <main className="collections-index" aria-labelledby="collections-title">
      <Breadcrumbs
        items={[{ label: "Home", to: base }, { label: "Collections" }]}
      />
      <PageHeader
        eyebrow="Your private workspace"
        title="Collections"
        id="collections-title"
        description="Gather exactly the Photos you need, refine the set, then download it."
        actions={
          <Button
            variant="primary"
            onClick={() => {
              create.reset();
              setCreateOpen(true);
            }}
          >
            <CollectionIcon name="plus" />
            New collection
          </Button>
        }
      />

      <section
        className="collections-toolbar"
        aria-label="Search and filter collections"
      >
        <div className="collections-controls">
          <div className="collections-search" ref={searchWrap}>
            <CollectionIcon name="search" />
            <input
              ref={searchInput}
              type="search"
              role="combobox"
              value={query}
              placeholder="Search collections…"
              aria-label="Search collections…"
              aria-autocomplete="list"
              aria-expanded={suggestionsOpen}
              aria-controls={suggestionListId}
              onKeyDown={handleSearchKeys}
              onFocus={() => {
                setSuggestionsOpen(true);
              }}
              onChange={(event) => {
                setQuery(event.target.value);
                setSuggestionsOpen(true);
              }}
            />
            {query !== "" && (
              <button
                type="button"
                className="collections-clear-search"
                aria-label="Clear search"
                onClick={() => {
                  setQuery("");
                  setServerQuery("");
                }}
              >
                <CollectionIcon name="x" />
              </button>
            )}
            {suggestionsOpen && suggestions.length > 0 && (
              <div
                className="collections-suggestions"
                id={suggestionListId}
                role="listbox"
                aria-label="Collection suggestions"
              >
                <p>{query === "" ? "Try searching by" : "Suggestions"}</p>
                {suggestions.map((item) => (
                  <button
                    type="button"
                    role="option"
                    aria-selected={selectedFilters.includes(item.key)}
                    className="collections-suggestion"
                    key={item.key}
                    disabled={
                      item.key === "william" && williamCollectionId === null
                    }
                    onMouseDown={(event) => {
                      event.preventDefault();
                    }}
                    onKeyDown={handleSuggestionKeys}
                    onClick={() => {
                      addFilter(item.key);
                    }}
                  >
                    <span>
                      <b>{item.label}</b>
                      <small>{item.type}</small>
                    </span>
                    <CollectionIcon name="plus" />
                  </button>
                ))}
              </div>
            )}
          </div>

          <label className="collections-sort">
            <span>Sort</span>
            <select
              value={sort}
              onChange={(event) => {
                setSort(event.target.value as CollectionSort);
              }}
            >
              <option value="updated">Recently updated</option>
              <option value="name">A–Z</option>
            </select>
            <CollectionIcon name="chevron" />
          </label>

          <button
            type="button"
            className={`collections-filter-button${filtersOpen ? " is-active" : ""}`}
            aria-expanded={filtersOpen}
            onClick={() => {
              setFiltersOpen((current) => !current);
            }}
          >
            <CollectionIcon name="filters" />
            Filters
            {selectedFilters.length > 0 && (
              <span>{selectedFilters.length}</span>
            )}
          </button>

          <div className="collections-view-toggle" aria-label="Choose view">
            <button
              type="button"
              className={view === "grid" ? "is-active" : ""}
              aria-label="Grid view"
              aria-pressed={view === "grid"}
              onClick={() => {
                setView("grid");
              }}
            >
              <CollectionIcon name="grid" />
            </button>
            <button
              type="button"
              className={view === "list" ? "is-active" : ""}
              aria-label="List view"
              aria-pressed={view === "list"}
              onClick={() => {
                setView("list");
              }}
            >
              <CollectionIcon name="list" />
            </button>
          </div>
        </div>

        {(selectedFilters.length > 0 || filtersOpen) && (
          <div className="collections-filter-panel">
            {selectedFilters.map((filter) => (
              <button
                type="button"
                className="collections-filter-chip"
                key={filter}
                onClick={() => {
                  setSelectedFilters((current) =>
                    current.filter((item) => item !== filter),
                  );
                }}
              >
                {filterLabel(filter)}
                <CollectionIcon name="x" />
              </button>
            ))}
            {selectedFilters.length > 1 && (
              <button
                type="button"
                className="collections-clear-filters"
                onClick={() => {
                  setSelectedFilters([]);
                }}
              >
                Clear all
              </button>
            )}
            {filtersOpen && (
              <div className="collections-quick-filters">
                <span>Quick filters</span>
                {filterOptions.map((item) => {
                  const selected = selectedFilters.includes(item.key);
                  return (
                    <button
                      type="button"
                      className={selected ? "is-selected" : ""}
                      key={item.key}
                      aria-pressed={selected}
                      disabled={
                        item.key === "william" && williamCollectionId === null
                      }
                      onClick={() => {
                        toggleFilter(item.key);
                      }}
                    >
                      {item.type}: {item.label}
                      {selected && <CollectionIcon name="check" />}
                    </button>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </section>

      <aside className="collections-principle">
        <CollectionIcon name="sparkles" />
        <div>
          <b>Working sets, not Albums</b>
          <p>
            Collections contain Photo identities only. They are private to you
            and made for sorting, curating and exporting.
          </p>
        </div>
        <CollectionIcon name="lock" />
      </aside>

      {collections.data.length === 0 &&
      selectedFilters.length === 0 &&
      serverQuery === "" ? (
        <div className="collections-zero-state" role="status">
          <p>No Collections yet.</p>
        </div>
      ) : collections.data.length === 0 ? (
        <div className="collections-empty" role="status">
          <CollectionIcon name="search" />
          <h2>No collections found</h2>
          <p>Try another word or clear the current filters.</p>
          <button type="button" onClick={clearSearchAndFilters}>
            Clear search and filters
          </button>
        </div>
      ) : (
        <div
          className={`collections-grid collections-grid--${view}`}
          aria-busy={collections.isFetching}
        >
          {collections.data.map((item) => (
            <CollectionCard
              base={base}
              familySlug={familySlug}
              item={item}
              key={item.id}
            />
          ))}
        </div>
      )}

      <Dialog
        open={createOpen}
        className="collections-create-dialog"
        eyebrow="Personal working set"
        title="Create collection"
        description="Collections are private to you and can contain Photos from anywhere in the Family Space."
        pending={create.isPending}
        onClose={closeCreate}
      >
        <form className="collections-create-form" onSubmit={submit}>
          <label>
            Name
            <input
              data-autofocus
              required
              maxLength={120}
              value={name}
              placeholder="e.g. William’s birthday book"
              onChange={(event) => {
                setName(event.target.value);
              }}
            />
          </label>
          <label>
            Short description <span>Optional</span>
            <textarea
              maxLength={5000}
              value={description}
              placeholder="What are you gathering these Photos for?"
              onChange={(event) => {
                setDescription(event.target.value);
              }}
            />
          </label>
          <div className="collections-private-note">
            <CollectionIcon name="lock" />
            <span>
              <b>Private to you</b>
              <small>
                Sharing and privacy controls are not part of Collections in V1.
              </small>
            </span>
          </div>
          {create.isError && (
            <p className="collections-create-error" role="alert">
              The Collection could not be created.
            </p>
          )}
          <div className="collections-dialog-footer">
            <Button type="button" variant="secondary" onClick={closeCreate}>
              Cancel
            </Button>
            <Button
              type="submit"
              variant="primary"
              disabled={create.isPending || name.trim() === ""}
            >
              {create.isPending ? "Creating…" : "Create collection"}
            </Button>
          </div>
        </form>
      </Dialog>
    </main>
  );
}
