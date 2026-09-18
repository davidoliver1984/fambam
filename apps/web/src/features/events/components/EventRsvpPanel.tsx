import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";

import {
  useEventRsvpMutation,
  useEventRsvpsQuery,
} from "../hooks/useEventQueries";
import type { EventRsvpStatus } from "../types/event";

const choices: Array<{ value: EventRsvpStatus; label: string }> = [
  { value: "going", label: "Going" },
  { value: "not_attending", label: "Can't attend" },
  { value: "pending", label: "Not decided" },
];

export function EventRsvpPanel({
  familySlug,
  eventId,
}: {
  familySlug: string;
  eventId: string;
}) {
  const currentUser = useCurrentUserQuery();
  const rsvps = useEventRsvpsQuery(familySlug, eventId);
  const respond = useEventRsvpMutation(familySlug, eventId);

  if (rsvps.isPending || currentUser.isPending)
    return <p role="status">Loading responses…</p>;
  if (rsvps.isError || currentUser.isError)
    return <p role="alert">Responses are unavailable right now.</p>;

  const ownResponse = choices.find(({ value }) =>
    rsvps.data[value].some((entry) => entry.user.id === currentUser.data.id),
  )?.value;

  return (
    <section aria-labelledby="event-rsvp-title">
      <h2 id="event-rsvp-title">Who's coming</h2>
      <p>
        {rsvps.data.going.length} going · {rsvps.data.pending.length} awaiting a
        reply
      </p>
      {rsvps.data.going.length > 0 && (
        <ul aria-label="Going">
          {rsvps.data.going.map((entry) => (
            <li key={entry.id}>{entry.user.name}</li>
          ))}
        </ul>
      )}
      {ownResponse === undefined ? (
        <p>Only admitted Event participants can respond.</p>
      ) : (
        <div role="group" aria-label="Your response">
          <p>
            Your response:{" "}
            {choices.find((choice) => choice.value === ownResponse)?.label}
          </p>
          {choices.map((choice) => (
            <button
              key={choice.value}
              type="button"
              aria-pressed={ownResponse === choice.value}
              disabled={respond.isPending}
              onClick={() => {
                respond.mutate(choice.value);
              }}
            >
              {choice.label}
            </button>
          ))}
          {respond.isError && (
            <p role="alert">Your response could not be saved.</p>
          )}
          {respond.isSuccess && <p role="status">Your response was saved.</p>}
        </div>
      )}
    </section>
  );
}
