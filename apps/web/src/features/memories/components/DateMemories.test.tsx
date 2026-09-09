import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { DateMemories } from "@/features/memories/components/DateMemories";
import { server } from "@/test/msw/server";

const endpoint =
  "http://localhost:8082/api/families/mercer-family/memories/date-based";

function renderMemories() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/", element: <DateMemories familySlug="mercer-family" /> }],
    { initialEntries: ["/"] },
  );

  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("DateMemories", () => {
  it("explains why a Photo resurfaced and keeps its added date distinct", async () => {
    server.use(
      http.get(endpoint, () =>
        HttpResponse.json({
          data: [
            {
              photo_id: "photo-1",
              media_upload_id: "upload-1",
              label: "Christmas morning",
              reason: "On this day in 1984",
              historical_date: { precision: "exact", value: "1984-09-09" },
              added_at: "2026-09-08T12:00:00+00:00",
            },
          ],
        }),
      ),
    );
    renderMemories();

    expect(await screen.findByText("On this day in 1984")).toBeInTheDocument();
    expect(screen.getByText(/Added to fambam/)).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Christmas morning" }),
    ).toHaveAttribute("href", "/families/mercer-family/photos/photo-1");
  });

  it("renders loading, empty and error states", async () => {
    server.use(http.get(endpoint, () => HttpResponse.json({ data: [] })));
    renderMemories();
    expect(screen.getByRole("status")).toHaveTextContent(/finding/i);
    expect(await screen.findByText(/no photographs/i)).toBeInTheDocument();
    cleanup();

    server.use(
      http.get(endpoint, () => HttpResponse.json({}, { status: 500 })),
    );
    renderMemories();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      /could not be loaded/i,
    );
  });
});
