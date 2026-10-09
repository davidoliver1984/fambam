import type { ReactNode } from "react";

import { useAppearancePreference } from "@/features/account/hooks/useAppearancePreference";

import "./InvitationAcceptance.css";

export function InvitationFrame({ children }: { children: ReactNode }) {
  const { theme, setPreference } = useAppearancePreference();

  return (
    <div className="invitation-page">
      <span className="invitation-page__glow invitation-page__glow--one" />
      <span className="invitation-page__glow invitation-page__glow--two" />
      <header className="invitation-header">
        <a className="invitation-brand" href="/" aria-label="Fambam home">
          Fambam <span aria-hidden="true">♥</span>
        </a>
        <button
          className="invitation-theme"
          type="button"
          aria-label={`Use ${theme === "dark" ? "light" : "dark"} mode`}
          onClick={() => {
            setPreference(theme === "dark" ? "light" : "dark");
          }}
        >
          {theme === "dark" ? <SunIcon /> : <MoonIcon />}
        </button>
      </header>
      {children}
      <footer className="invitation-footer">
        Private by design <span aria-hidden="true">·</span> Made for families
      </footer>
    </div>
  );
}

function MoonIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
      <path d="M20 15.2A8.5 8.5 0 0 1 8.8 4 8.5 8.5 0 1 0 20 15.2Z" />
    </svg>
  );
}

function SunIcon() {
  return (
    <svg aria-hidden="true" viewBox="0 0 24 24" fill="none">
      <circle cx="12" cy="12" r="3.5" />
      <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
    </svg>
  );
}
