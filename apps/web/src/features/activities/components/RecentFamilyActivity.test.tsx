import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";
import { RecentFamilyActivity } from "@/features/activities/components/RecentFamilyActivity";

const endpoint =
  "http://localhost:8082/api/families/mercer-family/activities/recent";

function renderActivity() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/",
        element: <RecentFamilyActivity familySlug="mercer-family" />,
      },
    ],
    { initialEntries: ["/"] },
  );
  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("RecentFamilyActivity", () => {
  it("renders grouped activity with a domain link", async () => {
    server.use(
      http.get(endpoint, () =>
        HttpResponse.json({
          data: [
            {
              id: "activity-1",
              action_type: "photos_added_to_album",
              actor: { user_id: 1, name: "David", person_id: "person-1" },
              subject: { type: "album", id: "album-1", label: "Summer" },
              contribution_batch_id: "batch-1",
              photo_ids: ["photo-1", "photo-2"],
              photo_count: 2,
              created_at: "2026-09-09T12:00:00+00:00",
            },
          ],
        }),
      ),
    );
    renderActivity();

    expect(
      await screen.findByText(/David added 2 photos to Summer/),
    ).toHaveAttribute("href", "/families/mercer-family/albums/album-1");
  });

  it("renders loading, error and empty states safely", async () => {
    server.use(http.get(endpoint, () => HttpResponse.json({ data: [] })));
    renderActivity();
    expect(screen.getByRole("status")).toHaveTextContent(/loading/i);
    expect(
      await screen.findByText(/new Albums, Events, Stories/i),
    ).toBeInTheDocument();
    cleanup();

    server.use(
      http.get(endpoint, () => HttpResponse.json({}, { status: 500 })),
    );
    renderActivity();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      /could not be loaded/i,
    );
  });
});
