import { useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import {
  useCreateEventMutation,
  useDeletedEventsQuery,
  useEventsQuery,
  useRestoreEventMutation,
} from "../hooks/useEventQueries";

export function EventsPage() {
  const { familySlug = "" } = useParams();
  const events = useEventsQuery(familySlug);
  const canManage =
    events.data?.some((item) => item.permissions.can_delete) === true;
  const deleted = useDeletedEventsQuery(familySlug, canManage);
  const restore = useRestoreEventMutation(familySlug);
  const create = useCreateEventMutation(familySlug);
  const [name, setName] = useState("");
  const [startsOn, setStartsOn] = useState("");
  if (events.isPending) return <p role="status">Loading events…</p>;
  if (events.isError) return <p role="alert">Events could not be loaded.</p>;
  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), starts_on: startsOn || null },
      {
        onSuccess: () => {
          setName("");
          setStartsOn("");
        },
      },
    );
  };
  return (
    <main className="auth people" aria-labelledby="events-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="events-title">Events</h1>
      {events.data.length === 0 ? (
        <p>No events have been created yet.</p>
      ) : (
        <ul>
          {events.data.map((item) => (
            <li key={item.id}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/events/${item.id}`}
              >
                {item.name}
              </Link>
              {item.starts_on === null ? "" : ` — ${item.starts_on}`}
            </li>
          ))}
        </ul>
      )}
      <section aria-labelledby="create-event-title">
        <h2 id="create-event-title">Create an Event</h2>
        <form onSubmit={submit}>
          <label htmlFor="event-name">Name</label>
          <input
            id="event-name"
            value={name}
            onChange={(event) => {
              setName(event.target.value);
            }}
            required
          />
          <label htmlFor="event-start">Start date</label>
          <input
            id="event-start"
            type="date"
            value={startsOn}
            onChange={(event) => {
              setStartsOn(event.target.value);
            }}
          />
          <button type="submit" disabled={create.isPending}>
            Create Event
          </button>
        </form>
        {create.isError && <p role="alert">The Event could not be created.</p>}
      </section>
      {canManage && (
        <section aria-labelledby="removed-events-title">
          <h2 id="removed-events-title">Removed Events</h2>
          {deleted.isPending ? (
            <p role="status">Loading removed Events…</p>
          ) : deleted.isError ? (
            <p role="alert">Removed Events could not be loaded.</p>
          ) : deleted.data.length === 0 ? (
            <p>No Events have been removed.</p>
          ) : (
            <ul>
              {deleted.data.map((item) => (
                <li key={item.id}>
                  {item.name}{" "}
                  <button
                    type="button"
                    disabled={restore.isPending}
                    onClick={() => {
                      restore.mutate(item.id);
                    }}
                  >
                    Restore
                  </button>
                </li>
              ))}
            </ul>
          )}
        </section>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
