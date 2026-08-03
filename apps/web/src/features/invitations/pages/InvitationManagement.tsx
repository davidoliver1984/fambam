import { type SyntheticEvent, useState } from "react";

import {
  useIssueInvitationMutation,
  useTransitionInvitationMutation,
} from "../hooks/useInvitationMutations";
import { useInvitationsQuery } from "../hooks/useInvitationsQuery";
import type { Invitation, InvitationTransition } from "../types/invitation";

export function InvitationManagement() {
  const invitationsQuery = useInvitationsQuery();
  const issueInvitation = useIssueInvitationMutation();
  const transitionInvitation = useTransitionInvitationMutation();
  const [message, setMessage] = useState("");

  async function issue(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const email = data.get("invite_email");

    if (typeof email !== "string") {
      setMessage("That invitation could not be sent.");
      return;
    }

    try {
      await issueInvitation.mutateAsync(email);
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
      aria-labelledby="invitations-title"
    >
      <h2 id="invitations-title">Invitations</h2>
      <form
        onSubmit={(event) => {
          void issue(event);
        }}
      >
        <label htmlFor="invite-email">Relative&apos;s email address</label>
        <input
          id="invite-email"
          name="invite_email"
          type="email"
          autoComplete="email"
          required
        />
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
                  {invitation.status} · expires{" "}
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
