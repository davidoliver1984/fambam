import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import type { PropsWithChildren } from "react";
import { describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import { useSettingsOverviewQuery } from "../hooks/useSettingsOverviewQuery";
import {
  getSettingsOverview,
  parseSettingsOverview,
} from "./settingsOverviewApi";

const apiBaseUrl = "http://localhost:8082";
const overview = {
  counts: { people: 12, photos: 40, albums: 5, stories: 9 },
  archive_health: {
    photos_dated: { numerator: 30, denominator: 40, percentage: 75 },
    faces_identified: {
      numerator: 2,
      denominator: 3,
      percentage: 66.67,
    },
    people_connected: { numerator: 9, denominator: 12, percentage: 75 },
  },
};

describe("settingsOverviewApi", () => {
  it("fetches and parses the bounded Settings Overview contract", async () => {
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/settings/overview`,
        () => HttpResponse.json({ data: overview }),
      ),
    );

    await expect(getSettingsOverview("oliver-family")).resolves.toEqual(
      overview,
    );
  });

  it("rejects malformed counts, percentages, and missing archive metrics", () => {
    expect(() =>
      parseSettingsOverview({
        ...overview,
        counts: { ...overview.counts, photos: -1 },
      }),
    ).toThrow();
    expect(() =>
      parseSettingsOverview({
        ...overview,
        archive_health: {
          ...overview.archive_health,
          photos_dated: {
            numerator: 1,
            denominator: 1,
            percentage: 101,
          },
        },
      }),
    ).toThrow();
    expect(() =>
      parseSettingsOverview({
        ...overview,
        archive_health: { photos_dated: overview.archive_health.photos_dated },
      }),
    ).toThrow();
  });

  it("exposes the canonical query through the Work-facing hook", async () => {
    server.use(
      http.get(
        `${apiBaseUrl}/api/families/oliver-family/settings/overview`,
        () => HttpResponse.json({ data: overview }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const wrapper = ({ children }: PropsWithChildren) => (
      <QueryClientProvider client={client}>{children}</QueryClientProvider>
    );

    const { result } = renderHook(
      () => useSettingsOverviewQuery("oliver-family"),
      { wrapper },
    );

    await waitFor(() => {
      expect(result.current.isSuccess).toBe(true);
    });
    expect(result.current.data).toEqual(overview);
  });
});
