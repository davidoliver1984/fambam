import { useEffect, useId, useRef, useState } from "react";

import { Button } from "@/components/ui";
import type { FamilyMembership } from "@/features/people/types/person";

import type { EventAdmission } from "../types/event";
import {
  LockGlyph,
  PencilGlyph,
  PeopleGlyph,
  SearchGlyph,
  XGlyph,
} from "./EventGlyphs";
import { avatarTone, initials } from "./eventPresentation";

export function EventInviteDialog({
  open,
  eventName,
  memberships,
  admissions,
  pending,
  onClose,
  onAdmit,
  onInvite,
  onSubmitted,
}: {
  open: boolean;
  eventName: string;
  memberships: FamilyMembership[];
  admissions: EventAdmission[];
  pending: boolean;
  onClose: () => void;
  onAdmit: (membershipId: string) => Promise<void>;
  onInvite: (email: string) => Promise<void>;
  onSubmitted: (count: number) => void;
}) {
  const [query, setQuery] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState(false);
  const [invitees, setInvitees] = useState<
    Array<{
      membershipId: string;
      user: FamilyMembership["user"];
      role: "guest" | "contributor";
    }>
  >([]);
  const dialog = useRef<HTMLElement>(null);
  const search = useRef<HTMLInputElement>(null);
  const wasOpen = useRef(false);
  const titleId = useId();
  const descriptionId = useId();
  const activeAdmissions = admissions.filter(
    (admission) => admission.revoked_at === null,
  );
  const admittedIds = new Set(
    activeAdmissions.map((admission) => admission.membership_id),
  );
  const admissionByMembership = new Map(
    activeAdmissions.map((admission) => [admission.membership_id, admission]),
  );
  const selectedIds = new Set(invitees.map((invitee) => invitee.membershipId));
  const normalizedQuery = query.trim().toLocaleLowerCase();
  const matchesQuery = (name: string, email = "") =>
    normalizedQuery === "" ||
    name.toLocaleLowerCase().includes(normalizedQuery) ||
    email.toLocaleLowerCase().includes(normalizedQuery);
  const candidates =
    normalizedQuery === ""
      ? []
      : memberships.filter(
          (membership) =>
            membership.state === "active" &&
            matchesQuery(membership.user.name, membership.user.email),
        );
  const emailCandidate = query.trim();
  const canInviteEmail = /^\S+@\S+\.\S+$/.test(emailCandidate);
  const invitationCount = invitees.length + (canInviteEmail ? 1 : 0);
  const busy = pending || submitting;

  useEffect(() => {
    if (open && !wasOpen.current) {
      setInvitees(
        activeAdmissions
          .filter((admission) => admission.rsvp_status !== "not_attending")
          .map((admission) => ({
            membershipId: admission.membership_id,
            user: admission.user,
            role: admission.role === "contributor" ? "contributor" : "guest",
          })),
      );
    }
    if (!open && wasOpen.current) {
      setQuery("");
      setInvitees([]);
      setSubmitError(false);
    }
    wasOpen.current = open;
  }, [activeAdmissions, open]);

  useEffect(() => {
    if (!open) return;
    const previous = document.activeElement as HTMLElement | null;
    search.current?.focus();
    const handleKeyboard = (keyboardEvent: KeyboardEvent) => {
      if (keyboardEvent.key === "Escape" && !busy) {
        onClose();
        return;
      }
      if (keyboardEvent.key !== "Tab") return;
      const focusable = dialog.current?.querySelectorAll<HTMLElement>(
        'button:not(:disabled), input:not(:disabled), select:not(:disabled), [href], [tabindex]:not([tabindex="-1"])',
      );
      if (!focusable || focusable.length === 0) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (keyboardEvent.shiftKey && document.activeElement === first) {
        keyboardEvent.preventDefault();
        last.focus();
      } else if (!keyboardEvent.shiftKey && document.activeElement === last) {
        keyboardEvent.preventDefault();
        first.focus();
      }
    };
    document.addEventListener("keydown", handleKeyboard);
    return () => {
      document.removeEventListener("keydown", handleKeyboard);
      previous?.focus();
    };
  }, [busy, onClose, open]);

  const submitInvitations = async () => {
    setSubmitting(true);
    setSubmitError(false);
    try {
      await Promise.all([
        ...invitees.map((invitee) => onAdmit(invitee.membershipId)),
        ...(canInviteEmail ? [onInvite(emailCandidate)] : []),
      ]);
      setQuery("");
      onSubmitted(invitationCount);
      onClose();
    } catch {
      setSubmitError(true);
    } finally {
      setSubmitting(false);
    }
  };

  if (!open) return null;

  return (
    <div className="ui-dialog-backdrop event-invite-backdrop">
      <section
        ref={dialog}
        className="ui-dialog event-invite-dialog"
        role="dialog"
        aria-modal="true"
        aria-labelledby={titleId}
        aria-describedby={descriptionId}
      >
        <div className="event-invite-dialog__header">
          <div>
            <p className="ui-eyebrow">{eventName}</p>
            <h2 id={titleId}>Invite people to this Event</h2>
            <p id={descriptionId}>
              These invitations only grant access to this Event, not the wider
              Family Space.
            </p>
          </div>
          <button
            type="button"
            className="event-invite-dialog__close"
            aria-label="Close invitations"
            disabled={busy}
            onClick={onClose}
          >
            <XGlyph />
          </button>
        </div>

        <label className="event-invite-search">
          <SearchGlyph />
          <span className="sr-only">
            Search People or enter an email address
          </span>
          <input
            ref={search}
            value={query}
            onChange={(changeEvent) => {
              setQuery(changeEvent.target.value);
            }}
            placeholder="Search People or enter an email address"
          />
        </label>

        <div className="event-invite-role-key" aria-label="Event access roles">
          <div>
            <span>
              <LockGlyph />
            </span>
            <b>Guest</b>
            <small>Limited invited access</small>
          </div>
          <div>
            <span>
              <PencilGlyph />
            </span>
            <b>Contributor</b>
            <small>Can add memories</small>
          </div>
        </div>

        {normalizedQuery !== "" && candidates.length > 0 && (
          <div className="event-invite-results" aria-label="People to invite">
            {candidates.slice(0, 5).map((membership) => (
              <div className="event-invite-result" key={membership.id}>
                <span
                  className={`event-invite-avatar${avatarTone(membership.user.name)}`}
                >
                  {initials(membership.user.name)}
                </span>
                <span>
                  <b>{membership.user.name}</b>
                  <small>{membership.user.email}</small>
                </span>
                <button
                  type="button"
                  disabled={
                    busy ||
                    selectedIds.has(membership.id) ||
                    admittedIds.has(membership.id)
                  }
                  onClick={() => {
                    setInvitees((current) => [
                      ...current,
                      {
                        membershipId: membership.id,
                        user: membership.user,
                        role:
                          membership.role === "contributor"
                            ? "contributor"
                            : "guest",
                      },
                    ]);
                    setQuery("");
                  }}
                >
                  {selectedIds.has(membership.id)
                    ? "Added"
                    : admittedIds.has(membership.id) &&
                        admissionByMembership.get(membership.id)
                          ?.rsvp_status !== "not_attending"
                      ? "Invited"
                      : admittedIds.has(membership.id)
                        ? "Declined"
                        : "Add"}
                </button>
              </div>
            ))}
          </div>
        )}

        <div className="event-invite-list" aria-label="People to invite">
          {invitees.map((invitee) => (
            <div className="event-invite-row" key={invitee.membershipId}>
              <span
                className={`event-invite-avatar${avatarTone(invitee.user.name)}`}
              >
                {initials(invitee.user.name)}
              </span>
              <span>
                <b>{invitee.user.name}</b>
                <small>{invitee.user.email}</small>
              </span>
              <label>
                <span className="sr-only">Role for {invitee.user.name}</span>
                <select
                  value={invitee.role}
                  disabled={busy}
                  onChange={(changeEvent) => {
                    const role = changeEvent.target.value as
                      "guest" | "contributor";
                    setInvitees((current) =>
                      current.map((person) =>
                        person.membershipId === invitee.membershipId
                          ? { ...person, role }
                          : person,
                      ),
                    );
                  }}
                >
                  <option value="guest">Guest</option>
                  <option value="contributor">Contributor</option>
                </select>
              </label>
              <button
                type="button"
                aria-label={`Remove ${invitee.user.name}`}
                disabled={busy}
                onClick={() => {
                  setInvitees((current) =>
                    current.filter(
                      (person) => person.membershipId !== invitee.membershipId,
                    ),
                  );
                }}
              >
                <XGlyph />
              </button>
            </div>
          ))}
          {invitees.length === 0 && candidates.length === 0 && (
            <div className="event-invite-empty">
              <PeopleGlyph />
              <b>
                {normalizedQuery === ""
                  ? "No one selected yet"
                  : "No matching family members"}
              </b>
              <p>
                {canInviteEmail
                  ? "Use Send invitation to invite this email address."
                  : "Search for a family member or enter an email address above."}
              </p>
            </div>
          )}
        </div>

        {submitError && (
          <p className="event-invite-error" role="alert">
            The invitations could not be sent. Nothing was removed from the
            list, so you can try again.
          </p>
        )}

        <footer className="event-invite-footer">
          <Button type="button" variant="secondary" onClick={onClose}>
            Cancel
          </Button>
          <Button
            type="button"
            variant="primary"
            disabled={invitationCount === 0 || busy}
            onClick={() => {
              void submitInvitations();
            }}
          >
            Send {invitationCount} invitation{invitationCount === 1 ? "" : "s"}
          </Button>
        </footer>
      </section>
    </div>
  );
}
