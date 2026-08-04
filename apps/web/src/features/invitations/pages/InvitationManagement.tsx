import { type SyntheticEvent, useState } from "react";

import {
  useIssueInvitationMutation,
  useTransitionInvitationMutation,
} from "../hooks/useInvitationMutations";
import { useInvitationsQuery } from "../hooks/useInvitationsQuery";
import type { Invitation, InvitationTransition } from "../types/invitation";

function isInvitationRole(
  value: FormDataEntryValue | null,
): value is Invitation["role"] {
  return (
    typeof value === "string" &&
    ["administrator", "member", "contributor", "guest"].includes(value)
  );
}

export function InvitationManagement({ familySlug }: { familySlug: string }) {
  const invitationsQuery = useInvitationsQuery(familySlug);
  const issueInvitation = useIssueInvitationMutation(familySlug);
  const transitionInvitation = useTransitionInvitationMutation(familySlug);
  const [message, setMessage] = useState("");

  async function issue(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const email = data.get("invite_email");
    const role = data.get("invite_role");

    if (typeof email !== "string" || !isInvitationRole(role)) {
      setMessage("That invitation could not be sent.");
      return;
    }

    try {
      await issueInvitation.mutateAsync({
        email,
        role,
      });
      form.reset();
      setMessage("Invitation sent.");
    } catch {
      setMessage("That invitation could not be sent.");
    }
  }

  async function transition(
    invitation: Invitation,
    action: InvitationTransition,
  ) {
    try {
      await transitionInvitation.mutateAsync({
        invitationId: invitation.id,
        action,
      });
      setMessage(
        action === "resend" ? "Invitation resent." : "Invitation revoked.",
      );
    } catch {
      setMessage("That invitation could not be changed.");
    }
  }

  return (
    <section
      className="invitation-management"
      aria-labelledby={`invitations-title-${familySlug}`}
    >
      <h2 id={`invitations-title-${familySlug}`}>Invitations</h2>
      <form
        onSubmit={(event) => {
          void issue(event);
        }}
      >
        <label htmlFor={`invite-email-${familySlug}`}>
          Relative&apos;s email address
        </label>
        <input
          id={`invite-email-${familySlug}`}
          name="invite_email"
          type="email"
          autoComplete="email"
          required
        />
        <label htmlFor={`invite-role-${familySlug}`}>Role</label>
        <select
          id={`invite-role-${familySlug}`}
          name="invite_role"
          defaultValue="member"
        >
          <option value="administrator">Administrator</option>
          <option value="member">Member</option>
          <option value="contributor">Contributor</option>
          <option value="guest">Guest</option>
        </select>
        <button type="submit" disabled={issueInvitation.isPending}>
          Send invitation
        </button>
      </form>
      {message !== "" && <p role="status">{message}</p>}
      {invitationsQuery.isPending && <p>Loading invitations…</p>}
      {invitationsQuery.isError && (
        <p role="alert">Invitations could not be loaded.</p>
      )}
      {invitationsQuery.isSuccess && invitationsQuery.data.length === 0 && (
        <p>No invitations have been sent yet.</p>
      )}
      {invitationsQuery.isSuccess && invitationsQuery.data.length > 0 && (
        <ul className="invitation-list">
          {invitationsQuery.data.map((invitation) => (
            <li key={invitation.id}>
              <span>
                <strong>{invitation.email}</strong>
                <small>
                  {invitation.role} · {invitation.status} · expires{" "}
                  {new Date(invitation.expires_at).toLocaleDateString()}
                </small>
              </span>
              {invitation.acceptable && (
                <span className="invitation-actions">
                  <button
                    className="secondary"
                    type="button"
                    disabled={transitionInvitation.isPending}
                    onClick={() => {
                      void transition(invitation, "resend");
                    }}
                  >
                    Resend
                  </button>
                  <button
                    className="secondary"
                    type="button"
                    disabled={transitionInvitation.isPending}
                    onClick={() => {
                      void transition(invitation, "revoke");
                    }}
                  >
                    Revoke
                  </button>
                </span>
              )}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
