import { useEffect, useRef, useState } from "react";
import { Link, NavLink, useNavigate } from "react-router";

import { useNotificationsQuery } from "@/features/notifications/hooks/useNotifications";

import type { FamilySpaceRole } from "../types/familySpace";
import { GlobalSearch } from "./GlobalSearch";
import { NotificationMenu } from "./NotificationMenu";
import { ShellIcon } from "./ShellIcon";

type ProductHeaderProps = {
  familySlug: string;
  familyName: string;
  currentUserPersonId: string | null;
  role: FamilySpaceRole;
  userName: string;
  theme: "light" | "dark";
  onThemeChange: (theme: "light" | "dark") => void;
  canBrowseArchive: boolean;
  canBrowseAlbums: boolean;
  signingOut: boolean;
  onSignOut: () => void;
};

function accountInitials(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

export function ProductHeader({
  familySlug,
  familyName,
  currentUserPersonId,
  role,
  userName,
  theme,
  onThemeChange,
  canBrowseArchive,
  canBrowseAlbums,
  signingOut,
  onSignOut,
}: ProductHeaderProps) {
  const navigate = useNavigate();
  const [mobileNav, setMobileNav] = useState(false);
  const [searchOpen, setSearchOpen] = useState(false);
  const [notificationOpen, setNotificationOpen] = useState(false);
  const mobileButton = useRef<HTMLButtonElement>(null);
  const searchWrap = useRef<HTMLDivElement>(null);
  const searchButton = useRef<HTMLButtonElement>(null);
  const notificationWrap = useRef<HTMLDivElement>(null);
  const notificationButton = useRef<HTMLButtonElement>(null);
  const accountMenu = useRef<HTMLDetailsElement>(null);
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const notifications = useNotificationsQuery(familySlug);
  const hasUnreadNotifications =
    notifications.data?.some((item) => item.read_at === null) ?? false;
  const canManageFamily = role === "owner" || role === "administrator";
  const nav = [
    { label: "Home", to: base, end: true, visible: true },
    { label: "People", to: `${base}/people`, visible: canBrowseArchive },
    { label: "Photos", to: `${base}/photos`, visible: canBrowseArchive },
    { label: "Albums", to: `${base}/albums`, visible: canBrowseAlbums },
    { label: "Events", to: `${base}/events`, visible: canBrowseArchive },
    { label: "Stories", to: `${base}/stories`, visible: true },
    { label: "Collections", to: `${base}/collections`, visible: true },
  ].filter((item) => item.visible);

  useEffect(() => {
    const closeOpenMenus = (event_: MouseEvent) => {
      const target = event_.target as Node;
      if (searchOpen && !searchWrap.current?.contains(target)) {
        setSearchOpen(false);
      }
      if (notificationOpen && !notificationWrap.current?.contains(target)) {
        setNotificationOpen(false);
      }
      if (accountMenu.current?.open && !accountMenu.current.contains(target)) {
        accountMenu.current.open = false;
      }
    };
    document.addEventListener("mousedown", closeOpenMenus);
    return () => {
      document.removeEventListener("mousedown", closeOpenMenus);
    };
  }, [notificationOpen, searchOpen]);

  useEffect(() => {
    const closeOnEscape = (event_: KeyboardEvent) => {
      if (event_.key !== "Escape") return;
      if (mobileNav) {
        setMobileNav(false);
        requestAnimationFrame(() => mobileButton.current?.focus());
      }
      if (searchOpen) {
        setSearchOpen(false);
        requestAnimationFrame(() => searchButton.current?.focus());
      }
      if (notificationOpen) {
        setNotificationOpen(false);
        requestAnimationFrame(() => notificationButton.current?.focus());
      }
      if (accountMenu.current?.open) {
        accountMenu.current.open = false;
        accountMenu.current.querySelector("summary")?.focus();
      }
    };
    window.addEventListener("keydown", closeOnEscape);
    return () => {
      window.removeEventListener("keydown", closeOnEscape);
    };
  }, [mobileNav, notificationOpen, searchOpen]);

  const closeSearch = () => {
    setSearchOpen(false);
    requestAnimationFrame(() => searchButton.current?.focus());
  };
  const closeNotifications = () => {
    setNotificationOpen(false);
    requestAnimationFrame(() => notificationButton.current?.focus());
  };
  const navigateAccount = (path: string) => {
    if (accountMenu.current) accountMenu.current.open = false;
    void navigate(path);
  };

  return (
    <header className="shell-global-nav">
      <Link
        className="shell-global-brand"
        to={base}
        aria-label={`${familyName} home`}
      >
        Fambam <ShellIcon name="heart" fill="currentColor" />
      </Link>
      <button
        ref={mobileButton}
        className="shell-mobile-menu"
        type="button"
        onClick={() => {
          setMobileNav((value) => !value);
        }}
        aria-label={mobileNav ? "Hide navigation" : "Show navigation"}
        aria-expanded={mobileNav}
        aria-controls="shell-mobile-navigation"
      >
        <ShellIcon name={mobileNav ? "x" : "menu"} />
      </button>
      <nav
        id="shell-mobile-navigation"
        className={mobileNav ? "mobile-open" : undefined}
        aria-label="Main navigation"
      >
        {nav.map((item) => (
          <NavLink
            key={item.label}
            to={item.to}
            end={item.end}
            className={({ isActive }) =>
              isActive ? "shell-nav-link active" : "shell-nav-link"
            }
            onClick={() => {
              setMobileNav(false);
            }}
          >
            {item.label}
          </NavLink>
        ))}
      </nav>
      <div className="shell-nav-actions">
        {canBrowseArchive && (
          <div className="shell-search-wrap" ref={searchWrap}>
            <button
              ref={searchButton}
              className="shell-nav-search"
              type="button"
              onClick={() => {
                setSearchOpen((value) => !value);
                setNotificationOpen(false);
                if (accountMenu.current) accountMenu.current.open = false;
              }}
              aria-label="Search family"
              aria-expanded={searchOpen}
              aria-controls="shell-global-search"
            >
              <ShellIcon name="search" />
              <span>Search family…</span>
            </button>
            <GlobalSearch
              familySlug={familySlug}
              open={searchOpen}
              onClose={closeSearch}
            />
          </div>
        )}
        <div className="shell-notification-boundary" ref={notificationWrap}>
          <button
            ref={notificationButton}
            className="shell-nav-icon"
            type="button"
            onClick={() => {
              setNotificationOpen((value) => !value);
              setSearchOpen(false);
              if (accountMenu.current) accountMenu.current.open = false;
            }}
            aria-label="Notifications"
            aria-expanded={notificationOpen}
            aria-controls="shell-notification-panel"
          >
            <ShellIcon name="bell" />
            <span
              className="shell-notification-badge"
              hidden={!hasUnreadNotifications}
              aria-hidden="true"
            />
          </button>
          <NotificationMenu
            familySlug={familySlug}
            open={notificationOpen}
            onClose={closeNotifications}
          />
        </div>
        <details className="shell-account" ref={accountMenu}>
          <summary
            className="shell-account-trigger"
            aria-label={`Open account menu for ${userName}`}
            onClick={() => {
              setSearchOpen(false);
              setNotificationOpen(false);
            }}
          >
            <span className="shell-avatar tiny" aria-hidden="true">
              {accountInitials(userName)}
            </span>
            <span>{userName.split(/\s+/)[0] || userName}</span>
            <ShellIcon name="chevron-down" />
          </summary>
          <div
            className="shell-account-menu"
            role="menu"
            tabIndex={-1}
            onKeyDown={(event_) => {
              if (
                !["ArrowDown", "ArrowUp", "Home", "End"].includes(event_.key)
              ) {
                return;
              }
              const items = Array.from(
                event_.currentTarget.querySelectorAll<HTMLButtonElement>(
                  '[role="menuitem"]:not(:disabled)',
                ),
              );
              if (items.length === 0) return;
              event_.preventDefault();
              const current = items.indexOf(
                document.activeElement as HTMLButtonElement,
              );
              const target =
                event_.key === "Home"
                  ? items[0]
                  : event_.key === "End"
                    ? items.at(-1)
                    : event_.key === "ArrowUp"
                      ? items[(current <= 0 ? items.length : current) - 1]
                      : items[(current + 1) % items.length];
              target?.focus();
            }}
          >
            <button
              type="button"
              role="menuitem"
              disabled={!currentUserPersonId}
              onClick={() => {
                if (currentUserPersonId) {
                  navigateAccount(
                    `${base}/people/${encodeURIComponent(currentUserPersonId)}`,
                  );
                }
              }}
            >
              <ShellIcon name="users" />
              View my Person page
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                navigateAccount("/account");
              }}
            >
              <ShellIcon name="pencil" />
              Edit profile
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                navigateAccount("/account");
              }}
            >
              <ShellIcon name="lock" />
              Account &amp; security
            </button>
            <button type="button" role="menuitem" disabled>
              <ShellIcon name="palette" />
              Appearance
            </button>
            <button
              type="button"
              role="menuitem"
              onClick={() => {
                onThemeChange(theme === "dark" ? "light" : "dark");
              }}
            >
              <ShellIcon name={theme === "dark" ? "sun" : "moon"} />
              {theme === "dark" ? "Light mode" : "Dark mode"}
            </button>
            <hr />
            <button
              type="button"
              role="menuitem"
              disabled={!canManageFamily}
              onClick={() => {
                navigateAccount(`${base}/settings`);
              }}
            >
              <ShellIcon name="settings" />
              Family settings
            </button>
            <button type="button" role="menuitem" disabled>
              <ShellIcon name="shield-check" />
              Family overview
            </button>
            <hr />
            <button type="button" role="menuitem" disabled>
              <ShellIcon name="activity" />
              Platform Admin
            </button>
            <hr />
            <button
              type="button"
              role="menuitem"
              disabled={signingOut}
              onClick={onSignOut}
            >
              <ShellIcon name="log-out" />
              {signingOut ? "Signing out…" : "Sign out"}
            </button>
          </div>
        </details>
      </div>
    </header>
  );
}
