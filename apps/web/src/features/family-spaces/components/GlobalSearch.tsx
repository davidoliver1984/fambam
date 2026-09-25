import { useEffect, useRef, useState } from "react";
import { useNavigate } from "react-router";

import { useArchiveSearchQuery } from "@/features/search/hooks/useArchiveSearchQuery";

import { PersonAvatar } from "./PersonAvatar";
import { ShellIcon } from "./ShellIcon";

type GlobalSearchProps = {
  familySlug: string;
  open: boolean;
  onClose: () => void;
};

function displayDate(value: string | null) {
  if (value === null) return null;
  const parsed = new Date(`${value}T00:00:00`);
  return Number.isNaN(parsed.valueOf())
    ? value
    : new Intl.DateTimeFormat("en-GB", {
        month: "long",
        year: "numeric",
      }).format(parsed);
}

export function GlobalSearch({ familySlug, open, onClose }: GlobalSearchProps) {
  const navigate = useNavigate();
  const input = useRef<HTMLInputElement>(null);
  const [query, setQuery] = useState("");
  const criteria = { ...(query.trim() === "" ? {} : { q: query.trim() }) };
  const people = useArchiveSearchQuery(familySlug, "people", criteria, open);
  const albums = useArchiveSearchQuery(familySlug, "albums", criteria, open);
  const events = useArchiveSearchQuery(familySlug, "events", criteria, open);

  useEffect(() => {
    if (open) input.current?.focus();
  }, [open]);

  if (!open) return null;

  const person = people.data?.pages[0]?.items[0];
  const album = albums.data?.pages[0]?.items[0];
  const event = events.data?.pages[0]?.items[0];
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const openResult = (path: string) => {
    onClose();
    void navigate(path);
  };
  const openAll = () => {
    const term = query.trim();
    const search = term === "" ? "" : `?${new URLSearchParams({ q: term })}`;
    openResult(`${base}/search${search}`);
  };
  const eventDate = event ? displayDate(event.starts_on) : null;

  return (
    <dialog
      id="shell-global-search"
      open
      className="shell-search-popover"
      aria-label="Search suggestions"
      onKeyDown={(event_) => {
        if (event_.key === "Escape") onClose();
      }}
    >
      <div className="shell-search-input">
        <ShellIcon name="search" />
        <input
          ref={input}
          type="search"
          aria-label="Search people, places and albums"
          value={query}
          onChange={(event_) => {
            setQuery(event_.target.value);
          }}
          onKeyDown={(event_) => {
            if (event_.key === "Enter") openAll();
          }}
          placeholder="Search people, places, albums…"
        />
      </div>
      <p className="shell-search-label">
        {query ? "Suggested results" : "Try searching for"}
      </p>
      {person && (
        <button
          type="button"
          onClick={() => {
            openResult(`${base}/people/${person.id}`);
          }}
        >
          <PersonAvatar
            name={person.preferred_name}
            portraitUrl={person.portrait_thumbnail_url ?? undefined}
          />
          <span>
            <b>{person.preferred_name}</b>
            <small>
              Person
              {person.relationship_to_viewer
                ? ` · ${person.relationship_to_viewer}`
                : ""}
            </small>
          </span>
          <ShellIcon name="chevron-right" />
        </button>
      )}
      {album && (
        <button
          type="button"
          onClick={() => {
            openResult(`${base}/albums/${album.id}`);
          }}
        >
          <span className="shell-result-thumb" aria-hidden="true">
            {album.cover_thumbnail_url && (
              <img src={album.cover_thumbnail_url} alt="" />
            )}
          </span>
          <span>
            <b>{album.name}</b>
            <small>
              Album
              {album.photo_count === undefined
                ? ""
                : ` · ${String(album.photo_count)} photographs`}
            </small>
          </span>
          <ShellIcon name="chevron-right" />
        </button>
      )}
      {event && (
        <button
          type="button"
          onClick={() => {
            openResult(`${base}/events/${event.id}`);
          }}
        >
          <span className="shell-result-icon" aria-hidden="true">
            <ShellIcon name="map-pin" />
          </span>
          <span>
            <b>{event.name}</b>
            <small>
              Event
              {eventDate ? ` · ${eventDate}` : ""}
            </small>
          </span>
          <ShellIcon name="chevron-right" />
        </button>
      )}
      <button className="shell-all-results" type="button" onClick={openAll}>
        See all results for “{query || "Blackpool"}”
      </button>
    </dialog>
  );
}
