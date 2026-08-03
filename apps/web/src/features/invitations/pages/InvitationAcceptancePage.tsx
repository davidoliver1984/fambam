import { useEffect, useState } from "react";

import { InvitationAcceptanceForm } from "../components/InvitationAcceptanceForm";
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

  const message =
    invitationToken === null || claimMutation.isError
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
