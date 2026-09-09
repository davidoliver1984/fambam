import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { QuietFamilySpaceGuide } from "@/features/memories/components/QuietFamilySpaceGuide";
import { server } from "@/test/msw/server";

const apiBaseUrl = "http://localhost:8082/api/families/mercer-family";

function handlers(itemCount: number) {
  return [
    http.get(`${apiBaseUrl}/activities/recent`, () =>
      HttpResponse.json({
        data: Array.from({ length: itemCount }, (_, index) => ({ id: index })),
      }),
    ),
    http.get(`${apiBaseUrl}/memories/date-based`, () =>
      HttpResponse.json({ data: [] }),
    ),
    http.get(`${apiBaseUrl}/memories/homepage`, () =>
      HttpResponse.json({
        data: { recent_days: 30, people: [], stories: [] },
      }),
    ),
  ];
}

function renderGuide() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/",
        element: (
          <QuietFamilySpaceGuide
            familySlug="mercer-family"
            canExplorePeople
            canExploreEvents
            canExploreAlbums
          />
        ),
      },
    ],
    { initialEntries: ["/"] },
  );

  const rendered = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );

  return { ...rendered, queryClient };
}

afterEach(cleanup);

describe("QuietFamilySpaceGuide", () => {
  it("turns an empty homepage into useful archive entry points", async () => {
    server.use(...handlers(0));
    renderGuide();

    expect(
      await screen.findByRole("heading", {
        name: "Explore the family archive",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Browse photographs" }),
    ).toHaveAttribute("href", "/families/mercer-family/photos");
    expect(
      screen.getByRole("link", { name: "Explore People" }),
    ).toHaveAttribute("href", "/families/mercer-family/people");
    expect(
      screen.getByRole("link", { name: "Explore Albums" }),
    ).toHaveAttribute("href", "/families/mercer-family/albums");
    expect(
      screen.getByRole("link", { name: "Explore Events" }),
    ).toHaveAttribute("href", "/families/mercer-family/events");
  });

  it("does not add the fallback when the homepage has enough genuine content", async () => {
    server.use(...handlers(4));
    const { queryClient } = renderGuide();

    await waitFor(() => {
      expect(
        queryClient
          .getQueryCache()
          .findAll()
          .every((query) => query.state.status === "success"),
      ).toBe(true);
    });
    expect(
      screen.queryByRole("heading", { name: "Explore the family archive" }),
    ).not.toBeInTheDocument();
  });
});
