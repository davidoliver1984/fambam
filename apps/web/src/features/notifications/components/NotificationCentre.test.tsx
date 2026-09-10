import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { NotificationCentre } from "@/features/notifications/components/NotificationCentre";
import { server } from "@/test/msw/server";

const base = "http://localhost:8082/api/families/mercer-family";

function renderCentre() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "*", element: <NotificationCentre familySlug="mercer-family" /> }],
    { initialEntries: ["/"] },
  );
  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("NotificationCentre", () => {
  it("renders typed notifications and marks an unread item when opened", async () => {
    const read = vi.fn();
    server.use(
      http.get(`${base}/notifications`, () =>
        HttpResponse.json({
          data: [
            {
              id: "notice-1",
              category: "story",
              photo_id: "photo-1",
              album_id: null,
              story_id: "story-1",
              person_id: null,
              comment_id: null,
              read_at: null,
              created_at: "2026-09-09T18:00:00Z",
            },
          ],
        }),
      ),
      http.get(`${base}/notification-preferences`, () =>
        HttpResponse.json({ data: [] }),
      ),
      http.patch(`${base}/notifications/notice-1/read`, () => {
        read();
        return HttpResponse.json({
          data: { id: "notice-1", read_at: "2026-09-09T19:00:00Z" },
        });
      }),
    );
    renderCentre();
    const link = await screen.findByRole("link", { name: /New story/ });
    expect(link).toHaveAttribute(
      "href",
      "/families/mercer-family/photos/photo-1",
    );
    await userEvent.click(link);
    await waitFor(() => {
      expect(read).toHaveBeenCalledOnce();
    });
  });

  it("saves category and channel preferences through the feature mutation", async () => {
    let updated = false;
    const preferences = [
      { category: "contribution", channel: "email", enabled: false },
    ];
    server.use(
      http.get(`${base}/notifications`, () => HttpResponse.json({ data: [] })),
      http.get(`${base}/notification-preferences`, () =>
        HttpResponse.json({ data: preferences }),
      ),
      http.put(`${base}/notification-preferences`, async ({ request }) => {
        const body = (await request.json()) as {
          preferences: typeof preferences;
        };
        updated = body.preferences[0]?.enabled ?? false;
        return HttpResponse.json({ data: body.preferences });
      }),
    );
    renderCentre();
    await userEvent.click(screen.getByText("Notification preferences"));
    const checkbox = await screen.findByRole("checkbox", {
      name: /New photographs — email/,
    });
    await userEvent.click(checkbox);
    await waitFor(() => {
      expect(updated).toBe(true);
    });
  });
});
