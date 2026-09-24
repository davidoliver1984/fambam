import { Link } from "react-router";

import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";

import {
  useEventRsvpMutation,
  useEventRsvpsQuery,
} from "../hooks/useEventQueries";
import type { EventRsvpStatus } from "../types/event";

const choices: Array<{ value: EventRsvpStatus; label: string }> = [
  { value: "going", label: "Going" },
  { value: "not_attending", label: "Not attending" },
];

const groups: Array<{ value: EventRsvpStatus; label: string }> = [
  { value: "going", label: "Going" },
  { value: "pending", label: "Awaiting reply" },
  { value: "not_attending", label: "Not attending" },
];

function CheckGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      className="h-4 w-4 shrink-0"
    >
      <path d="M20 6 9 17l-5-5" />
    </svg>
  );
}

function XGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      className="h-4 w-4 shrink-0"
    >
      <path d="M18 6 6 18M6 6l12 12" />
    </svg>
  );
}

function UsersGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      className="h-4 w-4 shrink-0"
    >
      <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2" />
      <circle cx="9" cy="7" r="4" />
      <path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" />
    </svg>
  );
}

export function EventRsvpPanel({
  familySlug,
  eventId,
  onManageInvitations,
}: {
  familySlug: string;
  eventId: string;
  onManageInvitations?: () => void;
}) {
  const currentUser = useCurrentUserQuery();
  const rsvps = useEventRsvpsQuery(familySlug, eventId);
  const people = usePeopleQuery(familySlug);
  const respond = useEventRsvpMutation(familySlug, eventId);

  if (rsvps.isPending || currentUser.isPending)
    return <p role="status">Loading responses…</p>;
  if (rsvps.isError || currentUser.isError)
    return <p role="alert">Responses are unavailable right now.</p>;

  const ownResponse = groups.find(({ value }) =>
    rsvps.data[value].some((entry) => entry.user.id === currentUser.data.id),
  )?.value;
  const personByUserId = new Map(
    (people.data ?? []).flatMap((person) =>
      person.account_link === null
        ? []
        : [[person.account_link.account.id, person] as const],
    ),
  );

  return (
    <>
      <section
        className="event-people-card attendance-card"
        aria-labelledby="event-rsvp-title"
      >
        <div className="event-info-title">
          <span>
            <UsersGlyph />
          </span>
          <h3 id="event-rsvp-title">People</h3>
        </div>
        <div className="attendance-groups">
          {groups.map((group) => {
            const entries = rsvps.data[group.value];
            if (entries.length === 0) return null;
            return (
              <div key={group.value}>
                <b>
                  {group.label} <span>{entries.length}</span>
                </b>
                <small>
                  {entries.map((entry, index) => {
                    const person = personByUserId.get(entry.user.id);
                    const label =
                      entry.user.name.split(/\s+/)[0] ?? entry.user.name;
                    return (
                      <span key={entry.id}>
                        {index > 0 && ", "}
                        {person === undefined ? (
                          label
                        ) : (
                          <Link
                            className="event-person-link"
                            to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(person.id)}`}
                          >
                            {label}
                          </Link>
                        )}
                      </span>
                    );
                  })}
                </small>
              </div>
            );
          })}
          {rsvps.data.going.length === 0 &&
            rsvps.data.pending.length === 0 &&
            rsvps.data.not_attending.length === 0 && <p>No responses yet.</p>}
        </div>
        {onManageInvitations !== undefined && (
          <button
            type="button"
            className="text-button event-manage-invitations"
            onClick={onManageInvitations}
          >
            Manage invitations →
          </button>
        )}
      </section>
      <section
        className="rsvp-panel event-invitation-card"
        aria-labelledby="event-rsvp-your-response"
      >
        <div>
          <p className="ui-eyebrow">Your invitation</p>
          <h3 id="event-rsvp-your-response">
            {ownResponse === undefined ? "You're invited" : "Are you going?"}
          </h3>
          <p>
            {ownResponse === undefined
              ? "Only admitted Event participants can respond."
              : "You can change your answer at any time."}
          </p>
        </div>
        {ownResponse !== undefined && (
          <>
            <div
              className="rsvp-actions"
              role="group"
              aria-label="Your response"
            >
              {choices.map((choice) => (
                <button
                  key={choice.value}
                  type="button"
                  className={
                    ownResponse === choice.value
                      ? `active ${choice.value === "going" ? "going" : "no"}`
                      : ""
                  }
                  aria-pressed={ownResponse === choice.value}
                  disabled={respond.isPending}
                  onClick={() => {
                    respond.mutate(choice.value);
                  }}
                >
                  {choice.value === "going" ? <CheckGlyph /> : <XGlyph />}
                  {choice.label}
                </button>
              ))}
            </div>
            {respond.isError && (
              <p role="alert">Your response could not be saved.</p>
            )}
            {respond.isSuccess && <p role="status">Your response was saved.</p>}
          </>
        )}
      </section>
    </>
  );
}
