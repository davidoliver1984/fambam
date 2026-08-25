import { useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import {
  useCreateEventMutation,
  useEventsQuery,
} from "../hooks/useEventQueries";

export function EventsPage() {
  const { familySlug = "" } = useParams();
  const events = useEventsQuery(familySlug);
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
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
