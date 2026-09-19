import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { StoryPage } from "./StoryPage";

afterEach(cleanup);

describe("StoryPage", () => {
  it("renders safe rich text and links to its typed subject", async () => {
    server.use(
      http.get(
        "http://localhost:8082/api/families/mercer/stories/story-1",
        () =>
          HttpResponse.json({
            data: {
              id: "story-1",
              heading: "Grandad's camera",
              body: { schema_version: 1, blocks: [] },
              body_html: "<p>The camera came everywhere.</p>",
              body_plain_text: "The camera came everywhere.",
              subject: { type: "person", id: "person-1" },
              author: { id: 1, name: "David" },
              comments: [],
              created_at: "2026-09-19T08:00:00Z",
              edited_at: null,
              permissions: { can_edit: true, can_remove: true },
            },
          }),
      ),
      http.get(
        "http://localhost:8082/api/families/mercer/stories/story-1/love",
        () =>
          HttpResponse.json({
            data: { count: 0, loved_by_me: false, reactors: [] },
          }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/stories/:storyId",
          element: <StoryPage />,
        },
      ],
      { initialEntries: ["/families/mercer/stories/story-1"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );
    expect(
      await screen.findByRole("heading", { name: "Grandad's camera" }),
    ).toBeInTheDocument();
    expect(screen.getByText("The camera came everywhere.")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /View the person/ }),
    ).toHaveAttribute("href", "/families/mercer/people/person-1");
  });
});
