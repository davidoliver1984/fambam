import { useState, type ReactNode, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import {
  useArchiveSearchQuery,
  useSearchSuggestionsQuery,
} from "../hooks/useArchiveSearchQuery";
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
  const [selectedPeople, setSelectedPeople] = useState<SearchSuggestion[]>([]);
  const [selectedEvent, setSelectedEvent] = useState<SearchSuggestion | null>(
    null,
  );
  const [criteria, setCriteria] = useState<SearchCriteria | null>(null);
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
  const people = useArchiveSearchQuery(
    familySlug,
    "people",
    criteria,
    canSearchPeople,
  );
  const photos = useArchiveSearchQuery(familySlug, "photos", criteria);
  const albums = useArchiveSearchQuery(familySlug, "albums", criteria);
  const events = useArchiveSearchQuery(
    familySlug,
    "events",
    criteria,
    canSearchEvents,
  );
  const stories = useArchiveSearchQuery(familySlug, "stories", criteria);

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
    };
    if (Object.keys(next).length > 0) setCriteria(next);
  }

  const peopleItems = people.data?.pages.flatMap((page) => page.items) ?? [];
  const photoItems = photos.data?.pages.flatMap((page) => page.items) ?? [];
  const albumItems = albums.data?.pages.flatMap((page) => page.items) ?? [];
  const eventItems = events.data?.pages.flatMap((page) => page.items) ?? [];
  const storyItems = stories.data?.pages.flatMap((page) => page.items) ?? [];
  const loading =
    photos.isPending ||
    albums.isPending ||
    stories.isPending ||
    (canSearchPeople && people.isPending) ||
    (canSearchEvents && events.isPending);
  const failed =
    photos.isError ||
    albums.isError ||
    stories.isError ||
    (canSearchPeople && people.isError) ||
    (canSearchEvents && events.isError);
  const hasCriteria =
    term.trim() !== "" ||
    dateFrom !== "" ||
    dateTo !== "" ||
    selectedPeople.length > 0 ||
    selectedEvent !== null;
  const discover = (type: string, id: string) =>
    `/families/${encodeURIComponent(familySlug)}/discover/${type}/${encodeURIComponent(id)}`;

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

      {criteria === null && <p>Enter words, filters, a date range, or both.</p>}
      {criteria !== null && loading && <p role="status">Searching…</p>}
      {criteria !== null && failed && (
        <p role="alert">The archive search could not be completed.</p>
      )}
      {criteria !== null && !loading && !failed && (
        <>
          {canSearchPeople && (
            <ResultSection
              title="People"
              empty={peopleItems.length === 0}
              more={<MoreButton label="People" query={people} />}
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
            more={<MoreButton label="Photos" query={photos} />}
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
            more={<MoreButton label="Albums" query={albums} />}
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
              more={<MoreButton label="Events" query={events} />}
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
            more={<MoreButton label="Stories" query={stories} />}
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
