import { act, renderHook } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  resolveAppearancePreference,
  useAppearancePreference,
} from "./useAppearancePreference";

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
  window.localStorage.clear();
  document.documentElement.removeAttribute("data-theme");
});

describe("useAppearancePreference", () => {
  it("accepts system, light and dark preferences", () => {
    expect(resolveAppearancePreference("system", false)).toBe("light");
    expect(resolveAppearancePreference("system", true)).toBe("dark");
    expect(resolveAppearancePreference("light", true)).toBe("light");
    expect(resolveAppearancePreference("dark", false)).toBe("dark");
  });

  it("tracks OS changes only while system is selected", () => {
    let listener: ((event: MediaQueryListEvent) => void) | undefined;
    const query = {
      matches: false,
      media: "(prefers-color-scheme: dark)",
      addEventListener: vi.fn((_name, callback) => {
        listener = callback as (event: MediaQueryListEvent) => void;
      }),
      removeEventListener: vi.fn(),
    } as unknown as MediaQueryList;
    vi.stubGlobal(
      "matchMedia",
      vi.fn(() => query),
    );
    window.localStorage.setItem("fambam-theme", "system");

    const { result } = renderHook(() => useAppearancePreference());
    expect(result.current.preference).toBe("system");
    expect(result.current.theme).toBe("light");
    act(() => listener?.({ matches: true } as MediaQueryListEvent));
    expect(result.current.theme).toBe("dark");

    act(() => {
      result.current.setPreference("light");
    });
    expect(result.current.theme).toBe("light");
    act(() => listener?.({ matches: false } as MediaQueryListEvent));
    expect(result.current.theme).toBe("light");
    expect(window.localStorage.getItem("fambam-theme")).toBe("light");
  });
});
