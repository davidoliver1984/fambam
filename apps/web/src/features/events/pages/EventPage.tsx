import { useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import {
  useDuplicateEventCandidatesQuery,
  useCreateEventAlbumMutation,
  useEventQuery,
  useUpdateEventMutation,
} from "../hooks/useEventQueries";

export function EventPage() {
  const { familySlug = "", eventId = "" } = useParams();
  const event = useEventQuery(familySlug, eventId);
  const duplicates = useDuplicateEventCandidatesQuery(familySlug, eventId);
  const update = useUpdateEventMutation(familySlug, eventId);
  const createAlbum = useCreateEventAlbumMutation(familySlug, eventId);
  const [albumName, setAlbumName] = useState("");
  if (event.isPending) return <p role="status">Loading Event…</p>;
  if (event.isError) return <p role="alert">This Event could not be loaded.</p>;
  const item = event.data;
  return (
    <main className="auth people" aria-labelledby="event-title">
      <p className="eyebrow">Event</p>
      <h1 id="event-title">{item.name}</h1>
      <p>
        {item.starts_on ?? "Date not recorded"}
        {item.ends_on === null ? "" : ` to ${item.ends_on}`}
      </p>
      <p>{item.location ?? "Location not recorded"}</p>
      <p>Status: {item.status}</p>
      {item.description !== null && <p>{item.description}</p>}
      {item.permissions.can_update && (
        <form
          onSubmit={(submitEvent: SyntheticEvent<HTMLFormElement>) => {
            submitEvent.preventDefault();
            const form = new FormData(submitEvent.currentTarget);
            const status = form.get("status");
            if (typeof status === "string") {
              update.mutate({ status: status as typeof item.status });
            }
          }}
        >
          <label htmlFor="event-status">Update status</label>
          <select id="event-status" name="status" defaultValue={item.status}>
            <option value="planned">Planned</option>
            <option value="active">Active</option>
            <option value="completed">Completed</option>
            <option value="archived">Archived</option>
          </select>
          <button type="submit" disabled={update.isPending}>
            Save Event
          </button>
        </form>
      )}
      <section aria-labelledby="event-albums">
        <h2 id="event-albums">Albums</h2>
        {item.albums?.length ? (
          <ul>
            {item.albums.map((album) => (
              <li key={album.id}>{album.name}</li>
            ))}
          </ul>
        ) : (
          <p>No Albums are linked yet.</p>
        )}
        <form
          onSubmit={(submitEvent) => {
            submitEvent.preventDefault();
            const name = albumName.trim();
            if (name !== "")
              createAlbum.mutate(name, {
                onSuccess: () => {
                  setAlbumName("");
                },
              });
          }}
        >
          <label htmlFor="event-album-name">New Event Album name</label>
          <input
            id="event-album-name"
            value={albumName}
            onChange={(changeEvent) => {
              setAlbumName(changeEvent.target.value);
            }}
            required
          />
          <button type="submit" disabled={createAlbum.isPending}>
            Create Event Album
          </button>
        </form>
      </section>
      <section aria-labelledby="event-attendees">
        <h2 id="event-attendees">People in confirmed photos</h2>
        {item.attendees?.length ? (
          <ul>
            {item.attendees.map((person) => (
              <li key={person.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/people/${person.id}`}
                >
                  {person.preferred_name}
                </Link>
              </li>
            ))}
          </ul>
        ) : (
          <p>No confirmed attendees yet.</p>
        )}
      </section>
      <section aria-labelledby="event-duplicates">
        <h2 id="event-duplicates">Possible duplicates</h2>
        {duplicates.isPending ? (
          <p role="status">Checking…</p>
        ) : duplicates.isError ? (
          <p role="alert">Duplicate suggestions could not be loaded.</p>
        ) : duplicates.data.length === 0 ? (
          <p>No likely duplicates found.</p>
        ) : (
          <ul>
            {duplicates.data.map((candidate) => (
              <li key={candidate.id}>
                <Link
                  to={`/families/${encodeURIComponent(familySlug)}/events/${candidate.id}`}
                >
                  {candidate.name}
                </Link>
              </li>
            ))}
          </ul>
        )}
        <p>
          <small>
            Suggestions are advisory; fambam never merges Events automatically.
          </small>
        </p>
      </section>
      <Link to={`/families/${encodeURIComponent(familySlug)}/events`}>
        Back to Events
      </Link>
    </main>
  );
}
