import { useEffect, useState } from "react";

import { toAppError } from "@/api/errors";

import { InvitationAcceptanceForm } from "../components/InvitationAcceptanceForm";
import { InvitationFrame } from "../components/InvitationFrame";
import { useInvitationClaimMutation } from "../hooks/useInvitationClaimMutation";

export function InvitationAcceptancePage() {
  const [invitationToken] = useState(() =>
    new URLSearchParams(window.location.hash.slice(1)).get("token"),
  );
  const claimMutation = useInvitationClaimMutation(invitationToken);

  useEffect(() => {
    window.history.replaceState(null, "", "/accept-invitation");
  }, []);

  if (claimMutation.data !== undefined) {
    return <InvitationAcceptanceForm claim={claimMutation.data} />;
  }

  const invalidOrExpired =
    invitationToken === null ||
    (claimMutation.isError && toAppError(claimMutation.error).status === 422);
  const message = invalidOrExpired
    ? "This invitation link is invalid or has expired."
    : claimMutation.isError
      ? "We couldn’t check this invitation. Please try again."
      : "Checking your invitation…";

  return (
    <InvitationFrame>
      <main className="invitation-state" aria-labelledby="page-title">
        <section className="invitation-card">
          <p className="invitation-card__eyebrow">Fambam invitation</p>
          <h1 id="page-title">Your invitation</h1>
          <p role="status">{message}</p>
          {invitationToken !== null &&
            claimMutation.isError &&
            !invalidOrExpired && (
              <button
                className="invitation-card__action"
                type="button"
                disabled={claimMutation.isPending}
                onClick={claimMutation.retry}
              >
                Try invitation again
              </button>
            )}
        </section>
      </main>
    </InvitationFrame>
  );
}
