import {
  type ReactNode,
  type SyntheticEvent,
  useEffect,
  useState,
} from "react";

import {
  logout,
  login,
  requestPasswordReset,
  resetPassword,
} from "./features/auth/api/authApi";
import { useCurrentUserQuery } from "./features/account/hooks/useCurrentUserQuery";
import { useUpdateProfileMutation } from "./features/account/hooks/useUpdateProfileMutation";
import { InvitationManagement } from "./features/invitations/pages/InvitationManagement";

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
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setMessage("Signing in…");

    try {
      await login({
        email: formString(data, "email"),
        password: formString(data, "password"),
        remember: data.get("remember") === "on",
      });
      window.location.assign("/account");
    } catch {
      setMessage("We could not sign you in. Check your details and try again.");
    }
  }

  return (
    <AuthShell
      title="Welcome back"
      introduction="Sign in to your private family archive."
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
        <label htmlFor="password">Password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="current-password"
          required
        />
        <label className="check" htmlFor="remember">
          <input id="remember" name="remember" type="checkbox" /> Remember me
        </label>
        <button type="submit">Sign in</button>
        <FormMessage message={message} />
      </form>
      <a href="/forgot-password">Forgotten your password?</a>
    </AuthShell>
  );
}

export function ForgotPasswordPage() {
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    await requestPasswordReset(formString(data, "email"));
    setMessage("If that address has an account, a reset link is on its way.");
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
        <button type="submit">Send reset link</button>
        <FormMessage message={message} />
      </form>
    </AuthShell>
  );
}

export function ResetPasswordPage() {
  const [message, setMessage] = useState("");
  const query = new URLSearchParams(window.location.search);

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    setMessage("Updating password…");

    try {
      await resetPassword({
        token: query.get("token"),
        email: query.get("email"),
        password: formString(data, "password"),
        password_confirmation: formString(data, "password_confirmation"),
      });
      window.location.assign("/login");
    } catch {
      setMessage("That reset link or password could not be accepted.");
    }
  }

  return (
    <AuthShell
      title="Choose a new password"
      introduction="Use a long, memorable passphrase or your password manager."
    >
      <form
        onSubmit={(event) => {
          void submit(event);
        }}
      >
        <label htmlFor="password">New password</label>
        <input
          id="password"
          name="password"
          type="password"
          autoComplete="new-password"
          required
        />
        <label htmlFor="password-confirmation">Confirm new password</label>
        <input
          id="password-confirmation"
          name="password_confirmation"
          type="password"
          autoComplete="new-password"
          required
        />
        <button type="submit">Save new password</button>
        <FormMessage message={message} />
      </form>
    </AuthShell>
  );
}

export function AccountPage() {
  const userQuery = useCurrentUserQuery();
  const updateProfile = useUpdateProfileMutation();
  const [message, setMessage] = useState("");
  const user = userQuery.data;

  useEffect(() => {
    if (userQuery.isError) window.location.assign("/login");
  }, [userQuery.isError]);

  async function update(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    await updateProfile.mutateAsync({
      name: formString(data, "name"),
      timezone: formString(data, "timezone"),
    });
    setMessage("Profile saved.");
  }

  async function signOut() {
    await logout();
    window.location.assign("/login");
  }

  return (
    <AuthShell
      title="Your account"
      introduction={user?.email ?? "Private account details"}
    >
      {user === undefined ? (
        <FormMessage
          message={userQuery.isPending ? "Loading your account…" : message}
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
      {user?.can_invite === true && <InvitationManagement />}
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
      <p className="eyebrow">Family Photo Archive</p>
      <h1 id="page-title">{title}</h1>
      <p>{introduction}</p>
      {children}
    </main>
  );
}
