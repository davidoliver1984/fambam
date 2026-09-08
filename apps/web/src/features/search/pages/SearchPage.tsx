import { useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import { useArchiveSearchQuery } from "../hooks/useArchiveSearchQuery";
import type { SearchCriteria } from "../types/search";

export function SearchPage() {
  const { familySlug = "" } = useParams();
  const [term, setTerm] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [criteria, setCriteria] = useState<SearchCriteria | null>(null);
  const photos = useArchiveSearchQuery(familySlug, "photos", criteria);
  const albums = useArchiveSearchQuery(familySlug, "albums", criteria);
  const stories = useArchiveSearchQuery(familySlug, "stories", criteria);

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const next = {
      ...(term.trim() === "" ? {} : { q: term.trim() }),
      ...(dateFrom === "" ? {} : { date_from: dateFrom }),
      ...(dateTo === "" ? {} : { date_to: dateTo }),
    };
    if (Object.keys(next).length > 0) setCriteria(next);
  }

  const photoItems = photos.data?.pages.flatMap((page) => page.items) ?? [];
  const albumItems = albums.data?.pages.flatMap((page) => page.items) ?? [];
  const storyItems = stories.data?.pages.flatMap((page) => page.items) ?? [];
  const loading = photos.isPending || albums.isPending || stories.isPending;
  const failed = photos.isError || albums.isError || stories.isError;

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
        <button
          type="submit"
          disabled={term.trim() === "" && dateFrom === "" && dateTo === ""}
        >
          Search archive
        </button>
      </form>

      {criteria === null && <p>Enter words, a date range, or both.</p>}
      {criteria !== null && loading && <p role="status">Searching…</p>}
      {criteria !== null && failed && (
        <p role="alert">The archive search could not be completed.</p>
      )}
      {criteria !== null && !loading && !failed && (
        <>
          <section aria-labelledby="search-photos-title">
            <h2 id="search-photos-title">Photos</h2>
            {photoItems.length === 0 ? (
              <p>No matching Photos.</p>
            ) : (
              <ul>
                {photoItems.map((photo) => (
                  <li key={photo.id}>
                    <Link
                      to={`/families/${encodeURIComponent(familySlug)}/photos/${photo.id}`}
                    >
                      {photo.caption ?? "Untitled Photo"}
                    </Link>
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
              </ul>
            )}
            {photos.hasNextPage && (
              <button
                type="button"
                disabled={photos.isFetchingNextPage}
                onClick={() => void photos.fetchNextPage()}
              >
                More Photos
              </button>
            )}
          </section>

          <section aria-labelledby="search-albums-title">
            <h2 id="search-albums-title">Albums</h2>
            {albumItems.length === 0 ? (
              <p>No matching Albums.</p>
            ) : (
              <ul>
                {albumItems.map((album) => (
                  <li key={album.id}>
                    <Link
                      to={`/families/${encodeURIComponent(familySlug)}/albums/${album.id}`}
                    >
                      {album.name}
                    </Link>
                    {album.description && <small>{album.description}</small>}
                  </li>
                ))}
              </ul>
            )}
            {albums.hasNextPage && (
              <button
                type="button"
                disabled={albums.isFetchingNextPage}
                onClick={() => void albums.fetchNextPage()}
              >
                More Albums
              </button>
            )}
          </section>

          <section aria-labelledby="search-stories-title">
            <h2 id="search-stories-title">Stories</h2>
            {storyItems.length === 0 ? (
              <p>No matching Stories.</p>
            ) : (
              <ul>
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
              </ul>
            )}
            {stories.hasNextPage && (
              <button
                type="button"
                disabled={stories.isFetchingNextPage}
                onClick={() => void stories.fetchNextPage()}
              >
                More Stories
              </button>
            )}
          </section>
        </>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
