import { type SyntheticEvent, useState } from "react";

import { useAcceptInvitationMutation } from "../hooks/useInvitationMutations";
import type { AcceptanceClaim } from "../types/invitation";

export function InvitationAcceptanceForm({
  claim,
}: {
  claim: AcceptanceClaim;
}) {
  const [message, setMessage] = useState("");
  const acceptInvitation = useAcceptInvitationMutation();

  async function accept(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const name = data.get("name");
    const password = data.get("password");
    const passwordConfirmation = data.get("password_confirmation");
    const timezone = data.get("timezone");
    setMessage("Creating your account…");

    if (
      typeof name !== "string" ||
      typeof password !== "string" ||
      typeof passwordConfirmation !== "string" ||
      typeof timezone !== "string"
    ) {
      setMessage(
        "Your account could not be created. Check the form and try again.",
      );
      return;
    }

    try {
      await acceptInvitation.mutateAsync({
        claim_token: claim.claim_token,
        name,
        password,
        password_confirmation: passwordConfirmation,
        timezone,
      });
      window.location.assign("/login");
    } catch {
      setMessage(
        "Your account could not be created. Check the form and try again.",
      );
    }
  }

  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">Family Photo Archive</p>
      <h1 id="page-title">Join your family archive</h1>
      <p>
        Invited email: <strong>{claim.email}</strong>
      </p>
      <form
        onSubmit={(event) => {
          void accept(event);
        }}
      >
        <label htmlFor="name">Display name</label>
        <input id="name" name="name" autoComplete="name" required />
        <label htmlFor="timezone">Timezone</label>
        <input
          id="timezone"
          name="timezone"
          defaultValue={
            Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC"
          }
          required
        />
        <label htmlFor="password">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          minLength={15}
          required
        />
        <label htmlFor="password-confirmation">Confirm password</label>
        <input
          id="password-confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          minLength={15}
          required
        />
        <button type="submit">Create private account</button>
        {message !== "" && <p role="status">{message}</p>}
      </form>
    </main>
  );
}
