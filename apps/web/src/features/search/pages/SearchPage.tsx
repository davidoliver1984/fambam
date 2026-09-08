import { useState, type ReactNode, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import { SavedSearchPanel } from "../components/SavedSearchPanel";
import {
  useArchiveSearchQuery,
  useSearchSuggestionsQuery,
} from "../hooks/useArchiveSearchQuery";
import { useSavedSearchResultsQuery } from "../hooks/useSavedSearches";
import type { SearchCriteria, SearchSuggestion } from "../types/search";

export function SearchPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const role = family.data?.role;
  const canSearchPeople =
    role === "owner" || role === "administrator" || role === "member";
  const canSearchEvents = canSearchPeople || role === "guest";
  const [term, setTerm] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [personPrefix, setPersonPrefix] = useState("");
  const [eventPrefix, setEventPrefix] = useState("");
  const [albumPrefix, setAlbumPrefix] = useState("");
  const [tagPrefix, setTagPrefix] = useState("");
  const [uploaderPrefix, setUploaderPrefix] = useState("");
  const [selectedPeople, setSelectedPeople] = useState<SearchSuggestion[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<SearchSuggestion | null>(
    null,
  );
  const [selectedAlbum, setSelectedAlbum] = useState<SearchSuggestion | null>(
    null,
  );
  const [selectedTag, setSelectedTag] = useState<SearchSuggestion | null>(null);
  const [selectedUploader, setSelectedUploader] =
    useState<SearchSuggestion | null>(null);
  const [visibility, setVisibility] = useState<
    "" | "family_space" | "selected" | "private"
  >("");
  const [criteria, setCriteria] = useState<SearchCriteria | null>(null);
  const [activeSavedSearch, setActiveSavedSearch] = useState<string | null>(
    null,
  );
  const peopleSuggestions = useSearchSuggestionsQuery(
    familySlug,
    "people",
    personPrefix,
    canSearchPeople,
  );
  const eventSuggestions = useSearchSuggestionsQuery(
    familySlug,
    "events",
    eventPrefix,
    canSearchEvents,
  );
  const albumSuggestions = useSearchSuggestionsQuery(
    familySlug,
    "albums",
    albumPrefix,
    true,
  );
  const tagSuggestions = useSearchSuggestionsQuery(
    familySlug,
    "tags",
    tagPrefix,
    true,
  );
  const uploaderSuggestions = useSearchSuggestionsQuery(
    familySlug,
    "uploaders",
    uploaderPrefix,
    true,
  );
  const people = useArchiveSearchQuery(
    familySlug,
    "people",
    criteria,
    canSearchPeople && activeSavedSearch === null,
  );
  const photos = useArchiveSearchQuery(
    familySlug,
    "photos",
    criteria,
    activeSavedSearch === null,
  );
  const albums = useArchiveSearchQuery(
    familySlug,
    "albums",
    criteria,
    activeSavedSearch === null,
  );
  const events = useArchiveSearchQuery(
    familySlug,
    "events",
    criteria,
    canSearchEvents && activeSavedSearch === null,
  );
  const stories = useArchiveSearchQuery(
    familySlug,
    "stories",
    criteria,
    activeSavedSearch === null,
  );
  const savedPeople = useSavedSearchResultsQuery(
    familySlug,
    activeSavedSearch,
    "people",
    canSearchPeople,
  );
  const savedPhotos = useSavedSearchResultsQuery(
    familySlug,
    activeSavedSearch,
    "photos",
  );
  const savedAlbums = useSavedSearchResultsQuery(
    familySlug,
    activeSavedSearch,
    "albums",
  );
  const savedEvents = useSavedSearchResultsQuery(
    familySlug,
    activeSavedSearch,
    "events",
    canSearchEvents,
  );
  const savedStories = useSavedSearchResultsQuery(
    familySlug,
    activeSavedSearch,
    "stories",
  );
  const peopleResults = activeSavedSearch === null ? people : savedPeople;
  const photoResults = activeSavedSearch === null ? photos : savedPhotos;
  const albumResults = activeSavedSearch === null ? albums : savedAlbums;
  const eventResults = activeSavedSearch === null ? events : savedEvents;
  const storyResults = activeSavedSearch === null ? stories : savedStories;

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const next = {
      ...(term.trim() === "" ? {} : { q: term.trim() }),
      ...(dateFrom === "" ? {} : { date_from: dateFrom }),
      ...(dateTo === "" ? {} : { date_to: dateTo }),
      ...(selectedPeople.length === 0
        ? {}
        : { person_ids: selectedPeople.map((person) => person.id) }),
      ...(selectedEvent === null ? {} : { event_id: selectedEvent.id }),
      ...(selectedAlbum === null ? {} : { album_id: selectedAlbum.id }),
      ...(selectedTag === null ? {} : { tag_id: selectedTag.id }),
      ...(selectedUploader === null
        ? {}
        : { uploaded_by: Number(selectedUploader.id) }),
      ...(visibility === "" ? {} : { visibility }),
    };
    if (Object.keys(next).length > 0) {
      setActiveSavedSearch(null);
      setCriteria(next);
    }
  }

  const peopleItems =
    peopleResults.data?.pages.flatMap((page) => page.items) ?? [];
  const photoItems =
    photoResults.data?.pages.flatMap((page) => page.items) ?? [];
  const albumItems =
    albumResults.data?.pages.flatMap((page) => page.items) ?? [];
  const eventItems =
    eventResults.data?.pages.flatMap((page) => page.items) ?? [];
  const storyItems =
    storyResults.data?.pages.flatMap((page) => page.items) ?? [];
  const loading =
    photoResults.isPending ||
    albumResults.isPending ||
    storyResults.isPending ||
    (canSearchPeople && peopleResults.isPending) ||
    (canSearchEvents && eventResults.isPending);
  const failed =
    photoResults.isError ||
    albumResults.isError ||
    storyResults.isError ||
    (canSearchPeople && peopleResults.isError) ||
    (canSearchEvents && eventResults.isError);
  const hasCriteria =
    term.trim() !== "" ||
    dateFrom !== "" ||
    dateTo !== "" ||
    selectedPeople.length > 0 ||
    selectedEvent !== null ||
    selectedAlbum !== null ||
    selectedTag !== null ||
    selectedUploader !== null ||
    visibility !== "";
  const discover = (type: string, id: string) =>
    `/families/${encodeURIComponent(familySlug)}/discover/${type}/${encodeURIComponent(id)}`;
  const hasActiveResults = criteria !== null || activeSavedSearch !== null;

  return (
    <main className="auth people" aria-labelledby="search-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="search-title">Search</h1>
      <form onSubmit={submit}>
        <label htmlFor="archive-search">Words or names</label>
        <input
          id="archive-search"
          type="search"
          value={term}
          onChange={(event) => {
            setTerm(event.target.value);
          }}
        />
        {canSearchPeople && (
          <fieldset>
            <legend>People in every matching Photo</legend>
            <label htmlFor="search-people">Find a Person</label>
            <input
              id="search-people"
              value={personPrefix}
              onChange={(event) => {
                setPersonPrefix(event.target.value);
              }}
            />
            {peopleSuggestions.data && peopleSuggestions.data.length > 0 && (
              <ul aria-label="Person suggestions">
                {peopleSuggestions.data
                  .filter(
                    (candidate) =>
                      !selectedPeople.some(
                        (selected) => selected.id === candidate.id,
                      ),
                  )
                  .map((candidate) => (
                    <li key={candidate.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedPeople((current) => [
                            ...current,
                            candidate,
                          ]);
                          setPersonPrefix("");
                        }}
                      >
                        Add {candidate.label}
                      </button>
                    </li>
                  ))}
              </ul>
            )}
            {selectedPeople.map((person) => (
              <button
                key={person.id}
                type="button"
                onClick={() => {
                  setSelectedPeople((current) =>
                    current.filter((item) => item.id !== person.id),
                  );
                }}
              >
                Remove {person.label}
              </button>
            ))}
          </fieldset>
        )}
        {canSearchEvents && (
          <fieldset>
            <legend>Event</legend>
            <label htmlFor="search-event">Find an Event</label>
            <input
              id="search-event"
              value={eventPrefix}
              onChange={(event) => {
                setEventPrefix(event.target.value);
              }}
            />
            {selectedEvent === null &&
              eventSuggestions.data &&
              eventSuggestions.data.length > 0 && (
                <ul aria-label="Event suggestions">
                  {eventSuggestions.data.map((candidate) => (
                    <li key={candidate.id}>
                      <button
                        type="button"
                        onClick={() => {
                          setSelectedEvent(candidate);
                          setEventPrefix("");
                        }}
                      >
                        Select {candidate.label}
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            {selectedEvent && (
              <button
                type="button"
                onClick={() => {
                  setSelectedEvent(null);
                }}
              >
                Remove {selectedEvent.label}
              </button>
            )}
          </fieldset>
        )}
        <fieldset>
          <legend>Album</legend>
          <label htmlFor="search-album">Find an Album</label>
          <input
            id="search-album"
            value={albumPrefix}
            onChange={(event) => {
              setAlbumPrefix(event.target.value);
            }}
          />
          {selectedAlbum === null &&
            albumSuggestions.data &&
            albumSuggestions.data.length > 0 && (
              <ul aria-label="Album suggestions">
                {albumSuggestions.data.map((candidate) => (
                  <li key={candidate.id}>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedAlbum(candidate);
                        setAlbumPrefix("");
                      }}
                    >
                      Select {candidate.label}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          {selectedAlbum && (
            <button
              type="button"
              onClick={() => {
                setSelectedAlbum(null);
              }}
            >
              Remove {selectedAlbum.label}
            </button>
          )}
        </fieldset>
        <fieldset>
          <legend>Tag</legend>
          <label htmlFor="search-tag">Find a tag</label>
          <input
            id="search-tag"
            value={tagPrefix}
            onChange={(event) => {
              setTagPrefix(event.target.value);
            }}
          />
          {selectedTag === null &&
            tagSuggestions.data &&
            tagSuggestions.data.length > 0 && (
              <ul aria-label="Tag suggestions">
                {tagSuggestions.data.map((candidate) => (
                  <li key={candidate.id}>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedTag(candidate);
                        setTagPrefix("");
                      }}
                    >
                      Select {candidate.label}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          {selectedTag && (
            <button
              type="button"
              onClick={() => {
                setSelectedTag(null);
              }}
            >
              Remove {selectedTag.label}
            </button>
          )}
        </fieldset>
        <fieldset>
          <legend>Uploader</legend>
          <label htmlFor="search-uploader">Find an uploader</label>
          <input
            id="search-uploader"
            value={uploaderPrefix}
            onChange={(event) => {
              setUploaderPrefix(event.target.value);
            }}
          />
          {selectedUploader === null &&
            uploaderSuggestions.data &&
            uploaderSuggestions.data.length > 0 && (
              <ul aria-label="Uploader suggestions">
                {uploaderSuggestions.data.map((candidate) => (
                  <li key={candidate.id}>
                    <button
                      type="button"
                      onClick={() => {
                        setSelectedUploader(candidate);
                        setUploaderPrefix("");
                      }}
                    >
                      Select {candidate.label}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          {selectedUploader && (
            <button
              type="button"
              onClick={() => {
                setSelectedUploader(null);
              }}
            >
              Remove {selectedUploader.label}
            </button>
          )}
        </fieldset>
        <label htmlFor="search-visibility">Visibility</label>
        <select
          id="search-visibility"
          value={visibility}
          onChange={(event) => {
            setVisibility(
              event.target.value as
                "" | "family_space" | "selected" | "private",
            );
          }}
        >
          <option value="">Any visibility I can access</option>
          <option value="family_space">Family Space</option>
          <option value="selected">Selected people</option>
          <option value="private">Private</option>
        </select>
        <label htmlFor="search-date-from">From</label>
        <input
          id="search-date-from"
          type="date"
          value={dateFrom}
          onChange={(event) => {
            setDateFrom(event.target.value);
          }}
        />
        <label htmlFor="search-date-to">To</label>
        <input
          id="search-date-to"
          type="date"
          min={dateFrom || undefined}
          value={dateTo}
          onChange={(event) => {
            setDateTo(event.target.value);
          }}
        />
        <button type="submit" disabled={!hasCriteria}>
          Search archive
        </button>
      </form>
      <SavedSearchPanel
        familySlug={familySlug}
        criteria={criteria}
        onRun={setActiveSavedSearch}
      />

      {!hasActiveResults && <p>Enter words, filters, a date range, or both.</p>}
      {hasActiveResults && loading && <p role="status">Searching…</p>}
      {hasActiveResults && failed && (
        <p role="alert">The archive search could not be completed.</p>
      )}
      {hasActiveResults && !loading && !failed && (
        <>
          {canSearchPeople && (
            <ResultSection
              title="People"
              empty={peopleItems.length === 0}
              more={<MoreButton label="People" query={peopleResults} />}
            >
              {peopleItems.map((person) => (
                <li key={person.id}>
                  <Link
                    to={`/families/${encodeURIComponent(familySlug)}/people/${person.id}`}
                  >
                    {person.preferred_name}
                  </Link>{" "}
                  <Link to={discover("people", person.id)}>
                    Explore related
                  </Link>
                </li>
              ))}
            </ResultSection>
          )}

          <ResultSection
            title="Photos"
            empty={photoItems.length === 0}
            more={<MoreButton label="Photos" query={photoResults} />}
          >
            {photoItems.map((photo) => (
              <li key={photo.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
                >
                  {photo.caption ?? "Untitled Photo"}
                </Link>{" "}
                <Link to={discover("photos", photo.id)}>Explore related</Link>
                {photo.historical_date?.value && (
                  <small>{photo.historical_date.value}</small>
                )}
                {photo.people.length > 0 && (
                  <small>
                    {photo.people
                      .map((person) => person.preferred_name)
                      .join(", ")}
                  </small>
                )}
              </li>
            ))}
          </ResultSection>

          <ResultSection
            title="Albums"
            empty={albumItems.length === 0}
            more={<MoreButton label="Albums" query={albumResults} />}
          >
            {albumItems.map((album) => (
              <li key={album.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}`}
                >
                  {album.name}
                </Link>{" "}
                <Link to={discover("albums", album.id)}>Explore related</Link>
                {album.description && <small>{album.description}</small>}
              </li>
            ))}
          </ResultSection>

          {canSearchEvents && (
            <ResultSection
              title="Events"
              empty={eventItems.length === 0}
              more={<MoreButton label="Events" query={eventResults} />}
            >
              {eventItems.map((familyEvent) => (
                <li key={familyEvent.id}>
                  <Link
                    to={`/families/${encodeURIComponent(familySlug)}/events/${familyEvent.id}`}
                  >
                    {familyEvent.name}
                  </Link>{" "}
                  <Link to={discover("events", familyEvent.id)}>
                    Explore related
                  </Link>
                  {familyEvent.location && (
                    <small>{familyEvent.location}</small>
                  )}
                </li>
              ))}
            </ResultSection>
          )}

          <ResultSection
            title="Stories"
            empty={storyItems.length === 0}
            more={<MoreButton label="Stories" query={storyResults} />}
          >
            {storyItems.map((story) => (
              <li key={story.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/photos/${story.photo_id}`}
                >
                  {story.photo_caption ?? "Story on an untitled Photo"}
                </Link>
                <p>{story.excerpt}</p>
              </li>
            ))}
          </ResultSection>
        </>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}

function ResultSection({
  title,
  empty,
  more,
  children,
}: {
  title: string;
  empty: boolean;
  more: ReactNode;
  children: ReactNode;
}) {
  const id = `search-${title.toLowerCase()}-title`;

  return (
    <section aria-labelledby={id}>
      <h2 id={id}>{title}</h2>
      {empty ? <p>No matching {title}.</p> : <ul>{children}</ul>}
      {more}
    </section>
  );
}

function MoreButton({
  label,
  query,
}: {
  label: string;
  query: {
    hasNextPage: boolean;
    isFetchingNextPage: boolean;
    fetchNextPage: () => Promise<unknown>;
  };
}) {
  if (!query.hasNextPage) return null;

  return (
    <button
      type="button"
      disabled={query.isFetchingNextPage}
      onClick={() => void query.fetchNextPage()}
    >
      More {label}
    </button>
  );
}
