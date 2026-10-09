import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch, type UseFormRegisterReturn } from "react-hook-form";

import { toLaravelFieldErrors } from "@/api/errors";

import { useAcceptInvitationMutation } from "../hooks/useInvitationMutations";
import type { AcceptanceClaim } from "../types/invitation";
import { storeInvitationWelcomeNotice } from "../welcomeNotice";
import {
  invitationAcceptanceSchema,
  type InvitationAcceptanceFields,
} from "../validation/invitationAcceptanceSchema";
import { InvitationFrame } from "./InvitationFrame";

export function InvitationAcceptanceForm({
  claim,
}: {
  claim: AcceptanceClaim;
}) {
  return (
    <InvitationFrame>
      {claim.existing_account ? (
        <ExistingAccountInvitationAcceptance claim={claim} />
      ) : (
        <NewAccountInvitationAcceptance claim={claim} />
      )}
    </InvitationFrame>
  );
}

function InvitationStory({ claim }: { claim: AcceptanceClaim }) {
  return (
    <section className="invitation-story" aria-labelledby="invitation-title">
      <p className="invitation-kicker">You’re invited</p>
      <h1 id="invitation-title">Come on in.</h1>
      <p className="invitation-story__lead">
        Join <strong>{claim.family_space_name}</strong> — a private home for the
        photographs, stories and moments your family wants to keep.
      </p>
      <InviterBadge claim={claim} />
    </section>
  );
}

function InviterBadge({ claim }: { claim: AcceptanceClaim }) {
  const initials = claim.inviter.name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");

  return (
    <div className="invitation-sender">
      <span className="invitation-sender__avatar" aria-hidden="true">
        {claim.inviter.avatar_url === null ? (
          initials || "F"
        ) : (
          <img src={claim.inviter.avatar_url} alt="" />
        )}
      </span>
      <span className="invitation-sender__copy">
        <small>Your invitation is from</small>
        <strong>{claim.inviter.name}</strong>
      </span>
    </div>
  );
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
      storeInvitationWelcomeNotice();
      window.location.assign(destination(accepted));
    } catch {
      setMessage(
        "Sign in with the invited account, then open the invitation link again.",
      );
    }
  }

  return (
    <main className="invitation-content">
      <InvitationStory claim={claim} />
      <section className="invitation-card" aria-labelledby="join-title">
        <p className="invitation-card__eyebrow">Your place is ready</p>
        <h2 id="join-title">Join the family.</h2>
        <p className="invitation-card__intro">
          Continue as <strong>{claim.email}</strong> to accept your invitation.
        </p>
        <div className="invitation-meta" aria-label="Invitation details">
          <span>{roleLabel(claim.role)}</span>
          <span>Private family space</span>
        </div>
        <button
          className="invitation-card__action"
          type="button"
          disabled={acceptInvitation.isPending}
          onClick={() => void accept()}
        >
          Join {claim.family_space_name}
        </button>
        {message !== "" && <p role="alert">{message}</p>}
      </section>
    </main>
  );
}

function NewAccountInvitationAcceptance({ claim }: { claim: AcceptanceClaim }) {
  const [message, setMessage] = useState("");
  const [visible, setVisible] = useState({
    password: false,
    confirmation: false,
  });
  const acceptInvitation = useAcceptInvitationMutation();
  const {
    register,
    handleSubmit,
    setError,
    control,
    formState: { errors },
  } = useForm<InvitationAcceptanceFields>({
    resolver: zodResolver(invitationAcceptanceSchema),
    defaultValues: {
      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "UTC",
    },
  });
  const password = useWatch({ control, name: "password", defaultValue: "" });
  const confirmation = useWatch({
    control,
    name: "password_confirmation",
    defaultValue: "",
  });
  const score = Math.min(
    4,
    [
      password.length >= 8,
      password.length >= 15,
      /[A-Z]/.test(password) && /[a-z]/.test(password),
      /\d/.test(password) && /[^A-Za-z0-9]/.test(password),
    ].filter(Boolean).length,
  );
  const strengthLabels = ["Add a password", "Weak", "Fair", "Good", "Strong"];
  const accept = handleSubmit(async (values) => {
    setMessage("Creating your account…");

    try {
      const accepted = await acceptInvitation.mutateAsync({
        claim_token: claim.claim_token,
        ...values,
      });
      storeInvitationWelcomeNotice();
      window.location.assign(destination(accepted));
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      for (const field of ["name", "password"] as const) {
        if (fields[field] !== undefined)
          setError(field, { message: fields[field] });
      }
      setMessage(
        "Your account could not be created. Check the form and try again.",
      );
    }
  });

  return (
    <main className="invitation-content">
      <InvitationStory claim={claim} />
      <section className="invitation-card" aria-labelledby="account-title">
        <p className="invitation-card__eyebrow">Welcome to Fambam</p>
        <h2 id="account-title">Create your account.</h2>
        <p className="invitation-card__intro">
          One small step, then you’re part of the family archive.
        </p>
        <div className="invitation-meta" aria-label="Invitation details">
          <span>{claim.email}</span>
          <span>{roleLabel(claim.role)}</span>
        </div>
        <form
          className="invitation-form"
          onSubmit={(event) => void accept(event)}
        >
          <label htmlFor="name">Your name</label>
          <input
            id="name"
            autoComplete="name"
            placeholder="How your family knows you"
            aria-describedby={errors.name ? "name-error" : undefined}
            {...register("name")}
          />
          {errors.name && (
            <p id="name-error" role="alert">
              {errors.name.message}
            </p>
          )}
          <label htmlFor="password">Create a password</label>
          <PasswordInput
            id="password"
            visible={visible.password}
            autoComplete="new-password"
            describedBy={errors.password ? "password-error" : "password-help"}
            placeholder="At least 15 characters"
            toggle={() => {
              setVisible((value) => ({ ...value, password: !value.password }));
            }}
            registration={register("password")}
          />
          <div className="password-strength" aria-live="polite">
            <span>
              <i style={{ width: `${String(score * 25)}%` }} />
            </span>
            <small id="password-help">
              {strengthLabels[score]}
              {password && score < 4
                ? " · Use 15+ characters, mixed case, a number and symbol"
                : ""}
            </small>
          </div>
          {errors.password && (
            <p id="password-error" role="alert">
              {errors.password.message}
            </p>
          )}
          <label htmlFor="password-confirmation">Confirm your password</label>
          <PasswordInput
            id="password-confirmation"
            visible={visible.confirmation}
            autoComplete="new-password"
            describedBy={
              errors.password_confirmation
                ? "password-confirmation-error"
                : undefined
            }
            placeholder="Enter it again"
            toggle={() => {
              setVisible((value) => ({
                ...value,
                confirmation: !value.confirmation,
              }));
            }}
            registration={register("password_confirmation")}
          />
          {confirmation &&
            password !== confirmation &&
            !errors.password_confirmation && (
              <p className="password-mismatch" role="alert">
                Passwords don’t match. Please check both entries.
              </p>
            )}
          {errors.password_confirmation && (
            <p id="password-confirmation-error" role="alert">
              {errors.password_confirmation.message}
            </p>
          )}
          <button
            className="invitation-form__submit"
            type="submit"
            disabled={acceptInvitation.isPending}
          >
            Create private account
          </button>
          {message !== "" && <p role="status">{message}</p>}
        </form>
        <p className="invitation-form__privacy">
          <span aria-hidden="true">●</span> Your family space is private and
          invitation-only.
        </p>
      </section>
    </main>
  );
}

function PasswordInput({
  id,
  visible,
  autoComplete,
  describedBy,
  placeholder,
  toggle,
  registration,
}: {
  id: string;
  visible: boolean;
  autoComplete: string;
  describedBy?: string;
  placeholder?: string;
  toggle: () => void;
  registration: UseFormRegisterReturn;
}) {
  return (
    <span className="password-input">
      <input
        id={id}
        type={visible ? "text" : "password"}
        autoComplete={autoComplete}
        aria-describedby={describedBy}
        placeholder={placeholder}
        {...registration}
      />
      <button
        type="button"
        onClick={toggle}
        aria-label={`${visible ? "Hide" : "Show"} password`}
      >
        <svg
          aria-hidden="true"
          viewBox="0 0 24 24"
          fill="none"
          stroke="currentColor"
          strokeWidth="1.8"
          strokeLinecap="round"
          strokeLinejoin="round"
        >
          {visible ? (
            <>
              <path d="m3 3 18 18" />
              <path d="M10.6 10.6a2 2 0 0 0 2.8 2.8M9.9 4.2A10.7 10.7 0 0 1 12 4c7 0 10 8 10 8a18.4 18.4 0 0 1-2.1 3.2M6.6 6.6C3.7 8.4 2 12 2 12s3 8 10 8a10 10 0 0 0 5.4-1.6" />
            </>
          ) : (
            <>
              <path d="M2 12s3-8 10-8 10 8 10 8-3 8-10 8S2 12 2 12" />
              <circle cx="12" cy="12" r="3" />
            </>
          )}
        </svg>
      </button>
    </span>
  );
}

function roleLabel(role: AcceptanceClaim["role"]): string {
  return `${role.charAt(0).toUpperCase()}${role.slice(1)} access`;
}

function destination(accepted: {
  family_slug: string;
  event_id: string | null;
}): string {
  return accepted.event_id === null
    ? `/families/${encodeURIComponent(accepted.family_slug)}`
    : `/families/${encodeURIComponent(accepted.family_slug)}/events/${encodeURIComponent(accepted.event_id)}`;
}
