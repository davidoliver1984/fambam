import { useEffect, useRef, useState } from "react";
import {
  Link,
  NavLink,
  Outlet,
  useLocation,
  useNavigate,
  useParams,
} from "react-router";

import { toAppError } from "@/api/errors";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { useLogoutMutation } from "@/features/auth/hooks/useAuthMutations";

import { useFamilySpaceQuery } from "../hooks/useFamilySpaceQuery";
import { useFamilySpacesQuery } from "../hooks/useFamilySpacesQuery";
import type { FamilySpaceRole } from "../types/familySpace";

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
  const families = useFamilySpacesQuery();
  const user = useCurrentUserQuery();
  const logout = useLogoutMutation();
  const [theme, setTheme] = useState<"light" | "dark">(initialTheme);
  const [menuOpen, setMenuOpen] = useState(false);
  const [actionError, setActionError] = useState("");
  const accountMenu = useRef<HTMLDetailsElement>(null);
  const menuButton = useRef<HTMLButtonElement>(null);
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
    function dismissOnEscape(event: KeyboardEvent) {
      if (event.key !== "Escape") return;
      const returnFocusToMenu = menuOpen;
      setMenuOpen(false);
      if (returnFocusToMenu) menuButton.current?.focus();
      if (accountMenu.current?.open) {
        accountMenu.current.open = false;
        accountMenu.current.querySelector("summary")?.focus();
      }
    }
    window.addEventListener("keydown", dismissOnEscape);
    return () => {
      window.removeEventListener("keydown", dismissOnEscape);
    };
  }, [menuOpen]);

  useEffect(() => {
    if (previousPath.current === location.pathname) return;
    previousPath.current = location.pathname;
    setMenuOpen(false);
    if (accountMenu.current?.open) accountMenu.current.open = false;
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
          <footer className="shell-footer">
            <div>
              <strong>Fambam</strong>
              <p>Your private invitation to a family Event.</p>
            </div>
            <Link to="/account">Your account</Link>
          </footer>
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
  const nav = [
    { label: "Home", to: base, end: true, visible: true },
    { label: "People", to: `${base}/people`, visible: canBrowseArchive },
    { label: "Photos", to: `${base}/photos`, visible: canBrowseArchive },
    { label: "Albums", to: `${base}/albums`, visible: canBrowseAlbums },
    { label: "Events", to: `${base}/events`, visible: canBrowseArchive },
    { label: "Stories", to: `${base}/stories`, visible: true },
    { label: "Collections", to: `${base}/collections`, visible: true },
  ].filter((item) => item.visible);

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
      <header className="shell-header">
        <div className="shell-header-inner">
          <Link
            className="shell-brand"
            to={base}
            aria-label={`${family.data.name} home`}
          >
            Fambam <span aria-hidden="true">♥</span>
          </Link>
          <nav className="shell-navigation" aria-label="Family navigation">
            {nav.map((item) => (
              <NavLink
                key={item.label}
                to={item.to}
                end={item.end}
                className={({ isActive }) => (isActive ? "active" : undefined)}
              >
                {item.label}
              </NavLink>
            ))}
          </nav>
          <div className="shell-actions">
            {canBrowseArchive && (
              <Link className="shell-search" to={`${base}/search`}>
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  fill="none"
                  stroke="currentColor"
                  strokeWidth="1.8"
                >
                  <circle cx="10.5" cy="10.5" r="6.5" />
                  <path d="m15.5 15.5 5 5" />
                </svg>
                <span>Search family…</span>
              </Link>
            )}
            <Link
              className="shell-notifications"
              to={`${base}#notifications`}
              aria-label="Notifications"
            >
              <svg
                aria-hidden="true"
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="1.8"
              >
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9ZM10 21h4" />
              </svg>
            </Link>
            <details className="shell-account" ref={accountMenu}>
              <summary
                aria-label={`Open account menu for ${user.data?.name ?? "your account"}`}
              >
                <span className="shell-avatar" aria-hidden="true">
                  {user.data?.name.slice(0, 1).toUpperCase() ?? "?"}
                </span>
                <span>{user.data?.name ?? "Account"}</span>
              </summary>
              <div className="shell-account-panel">
                <Link to="/account">Account &amp; security</Link>
                <Link to={`${base}/exports`}>Exports</Link>
                {(family.data.role === "owner" ||
                  family.data.role === "administrator") && (
                  <Link to={`${base}/settings`}>Family settings</Link>
                )}
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
            </details>
            <button
              className="shell-menu-button"
              ref={menuButton}
              type="button"
              aria-expanded={menuOpen}
              aria-controls="shell-mobile-navigation"
              onClick={() => {
                setMenuOpen(!menuOpen);
              }}
            >
              {menuOpen ? "Close" : "Menu"}
            </button>
          </div>
        </div>
        <nav
          id="shell-mobile-navigation"
          className="shell-mobile-navigation"
          aria-label="Mobile family navigation"
          hidden={!menuOpen}
        >
          {nav.map((item) => (
            <NavLink
              key={item.label}
              to={item.to}
              end={item.end}
              onClick={() => {
                setMenuOpen(false);
              }}
            >
              {item.label}
            </NavLink>
          ))}
          {canBrowseArchive && (
            <Link
              to={`${base}/search`}
              onClick={() => {
                setMenuOpen(false);
              }}
            >
              Search
            </Link>
          )}
        </nav>
      </header>
      <div className="shell-context">
        {families.isSuccess && families.data.length > 1 ? (
          <>
            <label htmlFor="family-switcher">Family Space</label>
            <select
              id="family-switcher"
              value={familySlug}
              onChange={(event) =>
                void navigate(
                  `/families/${encodeURIComponent(event.target.value)}`,
                )
              }
            >
              {families.data.map((item) => (
                <option key={item.id} value={item.slug}>
                  {item.name}
                </option>
              ))}
            </select>
          </>
        ) : (
          <>
            <span>Family Space</span>
            <strong>{family.data.name}</strong>
          </>
        )}
        <span className="shell-role">{family.data.role}</span>
        {families.isError && (
          <span role="status">Other Family Spaces could not be loaded.</span>
        )}
      </div>
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
      <footer className="shell-footer">
        <div>
          <strong>Fambam</strong>
          <p>A private place for family photographs, stories and memories.</p>
        </div>
        <div>
          <Link to={base}>Family home</Link>
          <Link to="/account">Your account</Link>
        </div>
        <small>Family memories, carefully kept.</small>
      </footer>
    </div>
  );
}
