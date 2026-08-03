import { zodResolver } from "@hookform/resolvers/zod";
import { useState } from "react";
import { useForm } from "react-hook-form";

import { toLaravelFieldErrors } from "@/api/errors";
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

function PasswordChangeForm() {
  const mutation = useUpdatePasswordMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<PasswordChangeFields>({
    resolver: zodResolver(passwordChangeSchema),
  });

  const submit = handleSubmit(async (values) => {
    try {
      await mutation.mutateAsync(values);
      window.location.assign("/login");
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      for (const field of ["current_password", "password"] as const) {
        if (fields[field] !== undefined)
          setError(field, { message: fields[field] });
      }
    }
  });

  return (
    <form onSubmit={(event) => void submit(event)}>
      <h3>Change password</h3>
      <label htmlFor="current-password">Current password</label>
      <input
        id="current-password"
        type="password"
        autoComplete="current-password"
        aria-describedby={
          errors.current_password ? "current-password-error" : undefined
        }
        {...register("current_password")}
      />
      {errors.current_password && (
        <p id="current-password-error" role="alert">
          {errors.current_password.message}
        </p>
      )}
      <label htmlFor="new-password">New password</label>
      <input
        id="new-password"
        type="password"
        autoComplete="new-password"
        aria-describedby={errors.password ? "new-password-error" : undefined}
        {...register("password")}
      />
      {errors.password && (
        <p id="new-password-error" role="alert">
          {errors.password.message}
        </p>
      )}
      <label htmlFor="new-password-confirmation">Confirm new password</label>
      <input
        id="new-password-confirmation"
        type="password"
        autoComplete="new-password"
        aria-describedby={
          errors.password_confirmation
            ? "new-password-confirmation-error"
            : undefined
        }
        {...register("password_confirmation")}
      />
      {errors.password_confirmation && (
        <p id="new-password-confirmation-error" role="alert">
          {errors.password_confirmation.message}
        </p>
      )}
      {mutation.isError && (
        <p role="alert">Your password could not be changed.</p>
      )}
      <button type="submit" disabled={mutation.isPending}>
        Change password
      </button>
    </form>
  );
}

function TwoFactorPanel({ enabled }: { enabled: boolean }) {
  const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);

  if (enabled) return <DisableTwoFactorForm />;
  if (recoveryCodes !== null)
    return <TwoFactorSetup recoveryCodes={recoveryCodes} />;

  return (
    <BeginTwoFactorForm
      onStarted={(codes) => {
        setRecoveryCodes(codes);
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
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CurrentPasswordFields>({
    resolver: zodResolver(currentPasswordSchema),
  });

  const submit = handleSubmit(async ({ current_password }) => {
    try {
      const recoveryCodes = await mutation.mutateAsync(current_password);
      onStarted(recoveryCodes);
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      const message = fields.password ?? fields.current_password;
      if (message !== undefined) setError("current_password", { message });
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
      {mutation.isError && (
        <p role="alert">Two-factor setup could not be started.</p>
      )}
      <button type="submit" disabled={mutation.isPending}>
        Set up authenticator
      </button>
    </form>
  );
}

function TwoFactorSetup({ recoveryCodes }: { recoveryCodes: string[] }) {
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

function DisableTwoFactorForm() {
  const mutation = useDisableTwoFactorMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<CurrentPasswordFields>({
    resolver: zodResolver(currentPasswordSchema),
  });
  const submit = handleSubmit(async ({ current_password }) => {
    try {
      await mutation.mutateAsync(current_password);
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      const message = fields.password ?? fields.current_password;
      if (message !== undefined) setError("current_password", { message });
    }
  });

  return (
    <form onSubmit={(event) => void submit(event)}>
      <h3>Two-factor authentication is enabled</h3>
      <label htmlFor="disable-mfa-password">Current password</label>
      <input
        id="disable-mfa-password"
        type="password"
        autoComplete="current-password"
        aria-describedby={
          errors.current_password ? "disable-mfa-password-error" : undefined
        }
        {...register("current_password")}
      />
      {errors.current_password && (
        <p id="disable-mfa-password-error" role="alert">
          {errors.current_password.message}
        </p>
      )}
      {mutation.isError && (
        <p role="alert">Two-factor authentication could not be disabled.</p>
      )}
      <button className="secondary" type="submit" disabled={mutation.isPending}>
        Disable authenticator
      </button>
    </form>
  );
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
