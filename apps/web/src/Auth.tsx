import {
  type ReactNode,
  type SyntheticEvent,
  useEffect,
  useState,
} from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { Link, useNavigate } from "react-router";

import { toLaravelFieldErrors } from "@/api/errors";
import { AccountSecurityPanel } from "@/features/account/components/AccountSecurityPanel";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { useUpdateProfileMutation } from "@/features/account/hooks/useUpdateProfileMutation";
import {
  useLoginMutation,
  useLogoutMutation,
  useRequestPasswordResetMutation,
  useResetPasswordMutation,
} from "@/features/auth/hooks/useAuthMutations";
import {
  confirmedPasswordSchema,
  type ConfirmedPasswordFields,
} from "@/features/auth/validation/passwordSchemas";
import {
  loginSchema,
  type LoginFields,
} from "@/features/auth/validation/loginSchema";
import { FamilySpaceManagement } from "@/features/family-spaces/components/FamilySpaceManagement";

function formString(data: FormData, name: string): string {
  const value = data.get(name);

  return typeof value === "string" ? value : "";
}

function FormMessage({ message }: { message: string }) {
  return message === "" ? null : (
    <p className="form-message" role="status">
      {message}
    </p>
  );
}

export function LoginPage() {
  const navigate = useNavigate();
  const [message, setMessage] = useState("");
  const [passwordVisible, setPasswordVisible] = useState(false);
  const currentUser = useCurrentUserQuery();
  const login = useLoginMutation();
  const returnTo = new URLSearchParams(window.location.search).get("returnTo");
  const safeReturnTo =
    returnTo?.startsWith("/families/") === true ? returnTo : "/account";
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<LoginFields>({
    resolver: zodResolver(loginSchema),
    defaultValues: { remember: false },
  });

  useEffect(() => {
    if (currentUser.isSuccess) void navigate(safeReturnTo, { replace: true });
  }, [currentUser.isSuccess, navigate, safeReturnTo]);

  const submit = handleSubmit(async (values) => {
    if (currentUser.isSuccess) {
      void navigate(safeReturnTo, { replace: true });
      return;
    }

    setMessage("Signing in…");

    try {
      const result = await login.mutateAsync(values);
      void navigate(result.two_factor ? "/two-factor-challenge" : safeReturnTo);
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      for (const field of ["email", "password"] as const) {
        if (fields[field] !== undefined)
          setError(field, { message: fields[field] });
      }
      setMessage("We could not sign you in. Check your details and try again.");
    }
  });

  return (
    <AuthShell
      title="Welcome back"
      introduction="Sign in to your private family archive."
    >
      <form onSubmit={(event) => void submit(event)}>
        <label htmlFor="email">Email address</label>
        <input
          id="email"
          type="email"
          autoComplete="email"
          aria-describedby={errors.email ? "login-email-error" : undefined}
          {...register("email")}
        />
        {errors.email && (
          <p id="login-email-error" role="alert">
            {errors.email.message}
          </p>
        )}
        <label htmlFor="password">Password</label>
        <span className="password-input">
          <input
            id="password"
            type={passwordVisible ? "text" : "password"}
            autoComplete="current-password"
            aria-describedby={
              errors.password ? "login-password-error" : undefined
            }
            {...register("password")}
          />
          <button
            type="button"
            onClick={() => {
              setPasswordVisible((visible) => !visible);
            }}
            aria-label={`${passwordVisible ? "Hide" : "Show"} password`}
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
              {passwordVisible ? (
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
        {errors.password && (
          <p id="login-password-error" role="alert">
            {errors.password.message}
          </p>
        )}
        <label className="check" htmlFor="remember">
          <input id="remember" type="checkbox" {...register("remember")} />
          Remember me
        </label>
        <button
          type="submit"
          disabled={login.isPending || currentUser.isPending}
        >
          Sign in
        </button>
        <FormMessage message={message} />
      </form>
      <Link to="/forgot-password">Forgotten your password?</Link>
    </AuthShell>
  );
}

export function ForgotPasswordPage() {
  const [message, setMessage] = useState("");
  const requestReset = useRequestPasswordResetMutation();

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    try {
      await requestReset.mutateAsync(formString(data, "email"));
      setMessage("If that address has an account, a reset link is on its way.");
    } catch {
      setMessage(
        "The reset request could not be sent. Please try again later.",
      );
    }
  }

  return (
    <AuthShell
      title="Reset your password"
      introduction="We will email a private reset link if the account exists."
    >
      <form
        onSubmit={(event) => {
          void submit(event);
        }}
      >
        <label htmlFor="email">Email address</label>
        <input
          id="email"
          name="email"
          type="email"
          autoComplete="email"
          required
        />
        <button type="submit" disabled={requestReset.isPending}>
          Send reset link
        </button>
        <FormMessage message={message} />
      </form>
    </AuthShell>
  );
}

export function ResetPasswordPage() {
  const navigate = useNavigate();
  const [message, setMessage] = useState("");
  const query = new URLSearchParams(window.location.search);
  const resetPassword = useResetPasswordMutation();
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ConfirmedPasswordFields>({
    resolver: zodResolver(confirmedPasswordSchema),
  });
  const submit = handleSubmit(async (values) => {
    try {
      await resetPassword.mutateAsync({
        token: query.get("token"),
        email: query.get("email"),
        ...values,
      });
      void navigate("/login");
    } catch (error) {
      const fields = toLaravelFieldErrors(error);
      if (fields.password !== undefined)
        setError("password", { message: fields.password });
      setMessage("That reset link or password could not be accepted.");
    }
  });

  return (
    <AuthShell
      title="Choose a new password"
      introduction="Use a long, memorable passphrase or your password manager."
    >
      <form onSubmit={(event) => void submit(event)}>
        <label htmlFor="password">New password</label>
        <input
          id="password"
          type="password"
          autoComplete="new-password"
          aria-describedby={
            errors.password ? "reset-password-error" : undefined
          }
          {...register("password")}
        />
        {errors.password && (
          <p id="reset-password-error" role="alert">
            {errors.password.message}
          </p>
        )}
        <label htmlFor="password-confirmation">Confirm new password</label>
        <input
          id="password-confirmation"
          type="password"
          autoComplete="new-password"
          aria-describedby={
            errors.password_confirmation
              ? "reset-password-confirmation-error"
              : undefined
          }
          {...register("password_confirmation")}
        />
        {errors.password_confirmation && (
          <p id="reset-password-confirmation-error" role="alert">
            {errors.password_confirmation.message}
          </p>
        )}
        <button type="submit" disabled={resetPassword.isPending}>
          Save new password
        </button>
        <FormMessage message={message} />
      </form>
    </AuthShell>
  );
}

export function AccountPage() {
  const navigate = useNavigate();
  const userQuery = useCurrentUserQuery();
  const updateProfile = useUpdateProfileMutation();
  const logout = useLogoutMutation();
  const [message, setMessage] = useState("");
  const user = userQuery.data;

  async function update(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    try {
      await updateProfile.mutateAsync({
        name: formString(data, "name"),
        timezone: formString(data, "timezone"),
      });
      setMessage("Profile saved.");
    } catch {
      setMessage("Your profile could not be saved.");
    }
  }

  async function signOut() {
    try {
      await logout.mutateAsync();
      void navigate("/login");
    } catch {
      setMessage("You could not be signed out. Please try again.");
    }
  }

  return (
    <AuthShell
      title="Your account"
      introduction={user?.email ?? "Private account details"}
    >
      {user === undefined ? (
        <FormMessage
          message={
            userQuery.isPending
              ? "Loading your account…"
              : "Returning you to sign in…"
          }
        />
      ) : (
        <form
          onSubmit={(event) => {
            void update(event);
          }}
        >
          <label htmlFor="name">Display name</label>
          <input
            id="name"
            name="name"
            defaultValue={user.name}
            autoComplete="name"
            required
          />
          <label htmlFor="timezone">Timezone</label>
          <input
            id="timezone"
            name="timezone"
            defaultValue={user.timezone}
            required
          />
          <button type="submit">Save profile</button>
          <button
            className="secondary"
            type="button"
            onClick={() => {
              void signOut();
            }}
          >
            Sign out
          </button>
          <FormMessage message={message} />
        </form>
      )}
      {user !== undefined && (
        <FamilySpaceManagement canCreate={user.can_create_family_spaces} />
      )}
      {user !== undefined && (
        <AccountSecurityPanel twoFactorEnabled={user.two_factor_enabled} />
      )}
    </AuthShell>
  );
}

function AuthShell({
  title,
  introduction,
  children,
}: {
  title: string;
  introduction: string;
  children: ReactNode;
}) {
  return (
    <main className="auth" aria-labelledby="page-title">
      <p className="eyebrow">fambam</p>
      <h1 id="page-title">{title}</h1>
      <p>{introduction}</p>
      {children}
    </main>
  );
}
