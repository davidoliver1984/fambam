import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm, useWatch, type UseFormRegisterReturn } from "react-hook-form";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import {
  useRevokeSessionsMutation,
  useUpdatePasswordMutation,
} from "@/features/account/hooks/useAccountSecurityMutations";
import {
  useBeginTwoFactorSetupMutation,
  useConfirmTwoFactorMutation,
  useDisableTwoFactorMutation,
} from "@/features/auth/hooks/useTwoFactorMutations";
import { useTwoFactorQrCodeQuery } from "@/features/auth/hooks/useTwoFactorQueries";
import {
  currentPasswordSchema,
  type CurrentPasswordFields,
} from "@/features/account/validation/currentPasswordSchema";
import {
  passwordChangeSchema,
  type PasswordChangeFields,
} from "@/features/account/validation/passwordChangeSchema";
import {
  twoFactorConfirmationSchema,
  type TwoFactorConfirmationFields,
} from "@/features/auth/validation/twoFactorConfirmationSchema";

export function AccountSecurityPanel({
  twoFactorEnabled,
}: {
  twoFactorEnabled: boolean;
}) {
  return (
    <section
      className="account-security"
      aria-labelledby="account-security-title"
    >
      <h2 id="account-security-title">Account security</h2>
      <PasswordChangeForm />
      <TwoFactorPanel enabled={twoFactorEnabled} />
      <RevokeSessionsButton />
    </section>
  );
}

export function PasswordChangeForm({
  submitLabel = "Change password",
  onSuccess,
}: {
  submitLabel?: string;
  onSuccess?: () => void;
} = {}) {
  const mutation = useUpdatePasswordMutation();
  const [visible, setVisible] = useState({
    current: false,
    password: false,
    confirmation: false,
  });
  const {
    register,
    handleSubmit,
    setError,
    control,
    reset,
    formState: { errors },
  } = useForm<PasswordChangeFields>({
    resolver: zodResolver(passwordChangeSchema),
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
  const labels = ["Add a password", "Weak", "Fair", "Good", "Strong"];

  const submit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values);
      reset();
      setVisible({ current: false, password: false, confirmation: false });
      onSuccess?.();
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      for (const field of ["current_password", "password"] as const) {
        if (fields[field] !== undefined)
          setError(field, { message: fields[field] });
      }
    }
  });

  return (
    <form
      className="settings-password-form"
      onSubmit={(event) => void submit(event)}
    >
      <h3>Change password</h3>
      <label htmlFor="current-password">Current password</label>
      <PasswordInput
        id="current-password"
        visible={visible.current}
        autoComplete="current-password"
        describedBy={
          errors.current_password ? "current-password-error" : undefined
        }
        toggle={() => {
          setVisible((value) => ({ ...value, current: !value.current }));
        }}
        registration={register("current_password")}
      />
      {errors.current_password && (
        <p id="current-password-error" role="alert">
          {errors.current_password.message}
        </p>
      )}
      <label htmlFor="new-password">New password</label>
      <PasswordInput
        id="new-password"
        visible={visible.password}
        autoComplete="new-password"
        describedBy={errors.password ? "new-password-error" : "password-help"}
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
          {labels[score]}
          {password && score < 4
            ? " · Use 15+ characters, mixed case, a number and symbol"
            : ""}
        </small>
      </div>
      {errors.password && (
        <p id="new-password-error" role="alert">
          {errors.password.message}
        </p>
      )}
      <label htmlFor="new-password-confirmation">Confirm new password</label>
      <PasswordInput
        id="new-password-confirmation"
        visible={visible.confirmation}
        autoComplete="new-password"
        describedBy={
          errors.password_confirmation
            ? "new-password-confirmation-error"
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
        <p id="new-password-confirmation-error" role="alert">
          {errors.password_confirmation.message}
        </p>
      )}
      {mutation.isError && (
        <p role="alert">Your password could not be changed.</p>
      )}
      <button type="submit" disabled={mutation.isPending}>
        {submitLabel}
      </button>
    </form>
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

export function TwoFactorPanel({
  enabled,
  onNotice,
}: {
  enabled: boolean;
  onNotice?: (title: string, description?: string) => void;
}) {
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);

  if (enabled) return <DisableTwoFactorForm onNotice={onNotice} />;
  if (recoveryCodes !== null)
    return <TwoFactorSetup recoveryCodes={recoveryCodes} onNotice={onNotice} />;

  return (
    <BeginTwoFactorForm
      onStarted={(codes) => {
        setRecoveryCodes(codes);
        onNotice?.(
          "Authenticator setup started",
          "Scan the QR code, save the recovery codes, then enter a six-digit code to finish.",
        );
      }}
    />
  );
}

function BeginTwoFactorForm({
  onStarted,
}: {
  onStarted: (recoveryCodes: string[]) => void;
}) {
  const mutation = useBeginTwoFactorSetupMutation();
  const [requestError, setRequestError] = useState("");
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CurrentPasswordFields>({
    resolver: zodResolver(currentPasswordSchema),
  });

  const submit = handleSubmit(async ({ current_password }) => {
    setRequestError("");
    try {
      const recoveryCodes = await mutation.mutateAsync(current_password);
      onStarted(recoveryCodes);
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      const message = fields.password ?? fields.current_password;
      if (message !== undefined) setError("current_password", { message });
      else
        setRequestError(
          securityRequestError(error, "Two-factor setup could not be started."),
        );
    }
  });

  return (
    <form onSubmit={(event) => void submit(event)}>
      <h3>Two-factor authentication</h3>
      <p>Add an authenticator app as an optional second sign-in step.</p>
      <label htmlFor="mfa-current-password">Current password</label>
      <input
        id="mfa-current-password"
        type="password"
        autoComplete="current-password"
        aria-describedby={
          errors.current_password ? "mfa-current-password-error" : undefined
        }
        {...register("current_password")}
      />
      {errors.current_password && (
        <p id="mfa-current-password-error" role="alert">
          {errors.current_password.message}
        </p>
      )}
      {requestError && <p role="alert">{requestError}</p>}
      <button type="submit" disabled={mutation.isPending}>
        Set up authenticator
      </button>
    </form>
  );
}

function TwoFactorSetup({
  recoveryCodes,
  onNotice,
}: {
  recoveryCodes: string[];
  onNotice?: (title: string, description?: string) => void;
}) {
  const qrCode = useTwoFactorQrCodeQuery(true);
  const confirmation = useConfirmTwoFactorMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<TwoFactorConfirmationFields>({
    resolver: zodResolver(twoFactorConfirmationSchema),
  });
  const submit = handleSubmit(async ({ code }) => {
    try {
      await confirmation.mutateAsync(code);
      onNotice?.(
        "Authenticator setup complete",
        "Your authenticator app is now required when you sign in.",
      );
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      if (fields.code !== undefined) setError("code", { message: fields.code });
    }
  });

  return (
    <div aria-labelledby="mfa-setup-title">
      <h3 id="mfa-setup-title">Finish authenticator setup</h3>
      {qrCode.isPending && <p role="status">Preparing secure setup…</p>}
      {qrCode.isError && <p role="alert">Setup details could not be loaded.</p>}
      {qrCode.data?.svg && (
        <img
          src={`data:image/svg+xml,${encodeURIComponent(qrCode.data.svg)}`}
          alt="Authenticator setup QR code"
        />
      )}
      {recoveryCodes.length > 0 ? (
        <>
          <p>Store these one-time recovery codes somewhere private:</p>
          <ul>
            {recoveryCodes.map((code) => (
              <li key={code}>
                <code>{code}</code>
              </li>
            ))}
          </ul>
        </>
      ) : (
        <p>No recovery codes are available.</p>
      )}
      <form onSubmit={(event) => void submit(event)}>
        <label htmlFor="mfa-confirmation-code">
          Six-digit authenticator code
        </label>
        <input
          id="mfa-confirmation-code"
          inputMode="numeric"
          autoComplete="one-time-code"
          aria-describedby={
            errors.code ? "mfa-confirmation-code-error" : undefined
          }
          {...register("code")}
        />
        {errors.code && (
          <p id="mfa-confirmation-code-error" role="alert">
            {errors.code.message}
          </p>
        )}
        {confirmation.isError && (
          <p role="alert">That authenticator code was not accepted.</p>
        )}
        <button type="submit" disabled={confirmation.isPending}>
          Confirm authenticator
        </button>
      </form>
    </div>
  );
}

function DisableTwoFactorForm({
  onNotice,
}: {
  onNotice?: (title: string, description?: string) => void;
}) {
  const [isConfirming, setIsConfirming] = useState(false);
  const [passwordVisible, setPasswordVisible] = useState(false);
  const mutation = useDisableTwoFactorMutation();
  const [requestError, setRequestError] = useState("");
  const {
    register,
    handleSubmit,
    setError,
    reset,
    formState: { errors },
  } = useForm<CurrentPasswordFields>({
    resolver: zodResolver(currentPasswordSchema),
  });
  const submit = handleSubmit(async ({ current_password }) => {
    setRequestError("");
    try {
      await mutation.mutateAsync(current_password);
      setIsConfirming(false);
      setPasswordVisible(false);
      onNotice?.(
        "Two-factor authentication disabled",
        "Your authenticator app is no longer required when you sign in.",
      );
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      const message = fields.password ?? fields.current_password;
      if (message !== undefined) setError("current_password", { message });
      else
        setRequestError(
          securityRequestError(
            error,
            "Two-factor authentication could not be disabled.",
          ),
        );
    }
  });

  if (!isConfirming) {
    return (
      <div className="two-factor-enabled-actions">
        <button
          className="secondary"
          type="button"
          onClick={() => {
            setIsConfirming(true);
          }}
        >
          Disable authenticator
        </button>
      </div>
    );
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      <h3>Disable two-factor authentication</h3>
      <p>Enter your current password to confirm this security change.</p>
      <label htmlFor="disable-mfa-password">Current password</label>
      <PasswordInput
        id="disable-mfa-password"
        visible={passwordVisible}
        autoComplete="current-password"
        describedBy={
          errors.current_password ? "disable-mfa-password-error" : undefined
        }
        toggle={() => {
          setPasswordVisible((visible) => !visible);
        }}
        registration={register("current_password")}
      />
      {errors.current_password && (
        <p id="disable-mfa-password-error" role="alert">
          {errors.current_password.message}
        </p>
      )}
      {requestError && <p role="alert">{requestError}</p>}
      <div className="two-factor-disable-actions">
        <button
          className="secondary"
          type="button"
          disabled={mutation.isPending}
          onClick={() => {
            setIsConfirming(false);
            setPasswordVisible(false);
            setRequestError("");
            reset();
          }}
        >
          Cancel
        </button>
        <button type="submit" disabled={mutation.isPending}>
          Disable authenticator
        </button>
      </div>
    </form>
  );
}

function securityRequestError(error: unknown, fallback: string): string {
  const status = toAppError(error).status;
  if (status === 419)
    return "Your session expired. Refresh the page and try again.";
  if (status === 423)
    return "Confirm your current password again, then retry this action.";
  if (status === 429)
    return "Too many attempts. Wait a minute, then try again.";
  return fallback;
}

function RevokeSessionsButton() {
  const mutation = useRevokeSessionsMutation();

  return (
    <div>
      <h3>Sign out everywhere</h3>
      <p>End every browser session and invalidate remembered sign-ins.</p>
      {mutation.isError && (
        <p role="alert">Your sessions could not be revoked.</p>
      )}
      <button
        className="secondary"
        type="button"
        disabled={mutation.isPending}
        onClick={() => {
          mutation.mutate(undefined, {
            onSuccess: () => {
              window.location.assign("/login");
            },
          });
        }}
      >
        Sign out everywhere
      </button>
    </div>
  );
}
