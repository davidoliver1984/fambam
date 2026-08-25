import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";

import { toLaravelFieldErrors } from "@/api/errors";

import { useAcceptInvitationMutation } from "../hooks/useInvitationMutations";
import type { AcceptanceClaim } from "../types/invitation";
import {
  invitationAcceptanceSchema,
  type InvitationAcceptanceFields,
} from "../validation/invitationAcceptanceSchema";

export function InvitationAcceptanceForm({
  claim,
}: {
  claim: AcceptanceClaim;
}) {
  if (claim.existing_account) {
    return <ExistingAccountInvitationAcceptance claim={claim} />;
  }

  return <NewAccountInvitationAcceptance claim={claim} />;
}

function ExistingAccountInvitationAcceptance({
  claim,
}: {
  claim: AcceptanceClaim;
}) {
  const [message, setMessage] = useState("");
  const acceptInvitation = useAcceptInvitationMutation();

  async function accept() {
    try {
      const accepted = await acceptInvitation.mutateAsync({
        claim_token: claim.claim_token,
      });
      window.location.assign(destination(accepted));
    } catch {
      setMessage(
        "Sign in with the invited account, then open the invitation link again.",
      );
    }
  }

  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">fambam</p>
      <h1 id="page-title">Join {claim.family_space_name}</h1>
      <p>
        Continue as <strong>{claim.email}</strong> with the {claim.role} role.
      </p>
      <button
        type="button"
        disabled={acceptInvitation.isPending}
        onClick={() => void accept()}
      >
        Join Family Space
      </button>
      {message !== "" && <p role="alert">{message}</p>}
    </main>
  );
}

function NewAccountInvitationAcceptance({ claim }: { claim: AcceptanceClaim }) {
  const [message, setMessage] = useState("");
  const acceptInvitation = useAcceptInvitationMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<InvitationAcceptanceFields>({
    resolver: zodResolver(invitationAcceptanceSchema),
    defaultValues: {
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
    },
  });
  const accept = handleSubmit(async (values) => {
    setMessage("Creating your account…");

    try {
      const accepted = await acceptInvitation.mutateAsync({
        claim_token: claim.claim_token,
        ...values,
      });
      window.location.assign(
        `/login?returnTo=${encodeURIComponent(destination(accepted))}`,
      );
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      for (const field of ["name", "timezone", "password"] as const) {
        if (fields[field] !== undefined)
          setError(field, { message: fields[field] });
      }
      setMessage(
        "Your account could not be created. Check the form and try again.",
      );
    }
  });

  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">fambam</p>
      <h1 id="page-title">Join your family archive</h1>
      <p>
        Family Space: <strong>{claim.family_space_name}</strong> ({claim.role})
      </p>
      <p>
        Invited email: <strong>{claim.email}</strong>
      </p>
      <form onSubmit={(event) => void accept(event)}>
        <label htmlFor="name">Display name</label>
        <input
          id="name"
          autoComplete="name"
          aria-describedby={errors.name ? "name-error" : undefined}
          {...register("name")}
        />
        {errors.name && (
          <p id="name-error" role="alert">
            {errors.name.message}
          </p>
        )}
        <label htmlFor="timezone">Timezone</label>
        <input
          id="timezone"
          aria-describedby={errors.timezone ? "timezone-error" : undefined}
          {...register("timezone")}
        />
        {errors.timezone && (
          <p id="timezone-error" role="alert">
            {errors.timezone.message}
          </p>
        )}
        <label htmlFor="password">Password</label>
        <input
          id="password"
          type="password"
          autoComplete="new-password"
          aria-describedby={errors.password ? "password-error" : undefined}
          {...register("password")}
        />
        {errors.password && (
          <p id="password-error" role="alert">
            {errors.password.message}
          </p>
        )}
        <label htmlFor="password-confirmation">Confirm password</label>
        <input
          id="password-confirmation"
          type="password"
          autoComplete="new-password"
          aria-describedby={
            errors.password_confirmation
              ? "password-confirmation-error"
              : undefined
          }
          {...register("password_confirmation")}
        />
        {errors.password_confirmation && (
          <p id="password-confirmation-error" role="alert">
            {errors.password_confirmation.message}
          </p>
        )}
        <button type="submit" disabled={acceptInvitation.isPending}>
          Create private account
        </button>
        {message !== "" && <p role="status">{message}</p>}
      </form>
    </main>
  );
}

function destination(accepted: {
  family_slug: string;
  event_id: string | null;
}): string {
  return accepted.event_id === null
    ? "/account"
    : `/families/${encodeURIComponent(accepted.family_slug)}/events/${encodeURIComponent(accepted.event_id)}`;
}
