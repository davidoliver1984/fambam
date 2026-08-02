import { useEffect, useState } from "react";

import { InvitationAcceptanceForm } from "../components/InvitationAcceptanceForm";
import { useInvitationClaimQuery } from "../hooks/useInvitationClaimQuery";

export function InvitationAcceptancePage() {
  const [invitationToken] = useState(() =>
    new URLSearchParams(window.location.hash.slice(1)).get("token"),
  );
  const claimQuery = useInvitationClaimQuery(invitationToken);

  useEffect(() => {
    window.history.replaceState(null, "", "/accept-invitation");
  }, []);

  if (claimQuery.data !== undefined) {
    return <InvitationAcceptanceForm claim={claimQuery.data} />;
  }

  const message =
    invitationToken === null || claimQuery.isError
      ? "This invitation link is invalid or has expired."
      : "Checking your invitation…";

  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">Family Photo Archive</p>
      <h1 id="page-title">Your invitation</h1>
      <p role="status">{message}</p>
    </main>
  );
}
