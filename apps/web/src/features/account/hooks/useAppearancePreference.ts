import { useEffect, useState } from "react";

export type AppearancePreference = "system" | "light" | "dark";
export type ResolvedTheme = "light" | "dark";

const storageKey = "fambam-theme";
const mediaQuery = "(prefers-color-scheme: dark)";

export function readAppearancePreference(): AppearancePreference {
  try {
    const stored = window.localStorage.getItem(storageKey);
    if (stored === "system" || stored === "light" || stored === "dark") {
      return stored;
    }
  } catch {
    // A blocked storage API must not prevent the archive from opening.
  }
  return "system";
}

export function resolveAppearancePreference(
  preference: AppearancePreference,
  systemDark: boolean,
): ResolvedTheme {
  return preference === "system" ? (systemDark ? "dark" : "light") : preference;
}

export function useAppearancePreference() {
  const [preference, setStoredPreference] = useState<AppearancePreference>(
    readAppearancePreference,
  );
  const [systemDark, setSystemDark] = useState(
    () =>
      typeof window.matchMedia === "function" &&
      window.matchMedia(mediaQuery).matches,
  );

  useEffect(() => {
    try {
      window.localStorage.setItem(storageKey, preference);
    } catch {
      // Appearance remains usable when storage is unavailable.
    }
  }, [preference]);

  useEffect(() => {
    if (preference !== "system" || typeof window.matchMedia !== "function") {
      return;
    }
    const query = window.matchMedia(mediaQuery);
    const update = (event: MediaQueryListEvent) => {
      setSystemDark(event.matches);
    };
    query.addEventListener("change", update);
    return () => {
      query.removeEventListener("change", update);
    };
  }, [preference]);

  const theme = resolveAppearancePreference(preference, systemDark);
  useEffect(() => {
    document.documentElement.dataset.theme = theme;
  }, [theme]);

  const setPreference = (next: AppearancePreference) => {
    if (next === "system" && typeof window.matchMedia === "function") {
      setSystemDark(window.matchMedia(mediaQuery).matches);
    }
    setStoredPreference(next);
  };

  return { preference, theme, setPreference };
}
