import { useEffect, useRef, useState } from "react";
import {
  Link,
  Outlet,
  useLocation,
  useNavigate,
  useParams,
} from "react-router";

import { toAppError } from "@/api/errors";
import { ProductFooter } from "@/components/ui";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { useLogoutMutation } from "@/features/auth/hooks/useAuthMutations";

import { useFamilySpaceQuery } from "../hooks/useFamilySpaceQuery";
import type { FamilySpaceRole } from "../types/familySpace";
import { ProductHeader } from "./ProductHeader";

import "./FamilyShell.css";

const fullMemberRoles: FamilySpaceRole[] = ["owner", "administrator", "member"];

type GuestRouteContext = {
  eventId: string | null;
};

function guestRouteContext(
  pathname: string,
  search: string,
  familySlug: string,
): GuestRouteContext | null {
  const segments = pathname.split("/").filter(Boolean);
  if (
    segments.length !== 4 ||
    segments[0] !== "families" ||
    segments[1] !== encodeURIComponent(familySlug) ||
    !["events", "albums", "photos"].includes(segments[2] ?? "")
  ) {
    return null;
  }

  const eventId =
    segments[2] === "events"
      ? (segments[3] ?? null)
      : new URLSearchParams(search).get("eventId");

  return { eventId };
}

function initialTheme(): "light" | "dark" {
  try {
    const saved = window.localStorage.getItem("fambam-theme");
    if (saved === "light" || saved === "dark") return saved;
  } catch {
    // A blocked storage API must not prevent the archive from opening.
  }
  return typeof window.matchMedia === "function" &&
    window.matchMedia("(prefers-color-scheme: dark)").matches
    ? "dark"
    : "light";
}

export function FamilyShell() {
  const { familySlug = "" } = useParams();
  const location = useLocation();
  const navigate = useNavigate();
  const family = useFamilySpaceQuery(familySlug);
  const user = useCurrentUserQuery();
  const logout = useLogoutMutation();
  const [theme, setTheme] = useState<"light" | "dark">(initialTheme);
  const [actionError, setActionError] = useState("");
  const content = useRef<HTMLDivElement>(null);
  const previousPath = useRef(location.pathname);
  const base = `/families/${encodeURIComponent(familySlug)}`;

  useEffect(() => {
    document.documentElement.dataset.theme = theme;
    try {
      window.localStorage.setItem("fambam-theme", theme);
    } catch {
      // Appearance remains usable even when storage is unavailable.
    }
  }, [theme]);

  useEffect(() => {
    if (previousPath.current === location.pathname) return;
    previousPath.current = location.pathname;
    content.current?.focus({ preventScroll: true });
  }, [location.pathname]);

  if (family.isPending) {
    return (
      <p className="shell-state" role="status">
        Opening Family Space…
      </p>
    );
  }

  if (family.isError) {
    const status = toAppError(family.error).status;
    const guestRoute = guestRouteContext(
      location.pathname,
      location.search,
      familySlug,
    );
    if (status === 403 && guestRoute !== null) {
      const eventPath =
        guestRoute.eventId === null
          ? null
          : `${base}/events/${encodeURIComponent(guestRoute.eventId)}`;

      return (
        <div className="family-shell bg-surface text-ink">
          <a className="shell-skip" href="#family-content">
            Skip to content
          </a>
          <header className="shell-header">
            <div className="shell-header-inner">
              <span className="shell-brand">Fambam ♥</span>
              <nav className="shell-navigation" aria-label="Guest navigation">
                {eventPath !== null && <Link to={eventPath}>Event</Link>}
                <Link to="/account">Account</Link>
              </nav>
              <div className="shell-actions">
                <button
                  type="button"
                  aria-pressed={theme === "dark"}
                  onClick={() => {
                    setTheme(theme === "dark" ? "light" : "dark");
                  }}
                >
                  {theme === "dark" ? "Use light mode" : "Use dark mode"}
                </button>
                <button
                  type="button"
                  disabled={logout.isPending}
                  onClick={() => void signOut()}
                >
                  Sign out
                </button>
              </div>
            </div>
          </header>
          {actionError && (
            <p className="shell-action-error" role="alert">
              {actionError}
            </p>
          )}
          <div id="family-content" className="shell-content" tabIndex={-1}>
            <Outlet />
          </div>
          <ProductFooter />
        </div>
      );
    }

    const unavailable = status === 404;
    return (
      <main className="shell-state" aria-labelledby="family-shell-error">
        <h1 id="family-shell-error">
          {unavailable
            ? "Family Space not found"
            : "Family Space could not be loaded"}
        </h1>
        <p>
          {unavailable
            ? "It is unavailable or you no longer have access."
            : "Please try again."}
        </p>
        <Link to="/account">Return to your account</Link>
      </main>
    );
  }

  const canBrowseArchive = fullMemberRoles.includes(family.data.role);
  const canBrowseAlbums = family.data.role !== "guest";
  async function signOut() {
    setActionError("");
    try {
      await logout.mutateAsync();
      void navigate("/login");
    } catch {
      setActionError("You could not be signed out. Please try again.");
    }
  }

  return (
    <div className="family-shell bg-surface text-ink">
      <a className="shell-skip" href="#family-content">
        Skip to content
      </a>
      <ProductHeader
        familySlug={familySlug}
        familyName={family.data.name}
        currentUserPersonId={family.data.current_user_person_id ?? null}
        role={family.data.role}
        userName={user.data?.name ?? "Account"}
        theme={theme}
        onThemeChange={setTheme}
        canBrowseArchive={canBrowseArchive}
        canBrowseAlbums={canBrowseAlbums}
        signingOut={logout.isPending}
        onSignOut={() => void signOut()}
      />
      {actionError && (
        <p className="shell-action-error" role="alert">
          {actionError}
        </p>
      )}
      <div
        id="family-content"
        className="shell-content"
        ref={content}
        tabIndex={-1}
      >
        <Outlet />
      </div>
      <ProductFooter familyHome={base} />
    </div>
  );
}
