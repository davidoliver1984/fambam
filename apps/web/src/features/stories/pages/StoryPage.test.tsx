import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
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
              subject: {
                type: "person",
                id: "person-1",
                label: "Grandad",
              },
              hero: null,
              author: {
                id: 1,
                display_name: "David",
                person_id: "person-author",
                initials: "D",
                portrait_thumbnail_url: null,
              },
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
    expect(screen.getByRole("link", { name: "Grandad" })).toHaveAttribute(
      "href",
      "/families/mercer/people/person-1",
    );
    expect(screen.getByRole("link", { name: "David" })).toHaveAttribute(
      "href",
      "/families/mercer/people/person-author",
    );
  });

  it("saves the existing structured document without flattening it", async () => {
    const document = {
      schema_version: 1 as const,
      blocks: [
        {
          type: "heading_2" as const,
          content: [
            {
              type: "text" as const,
              text: "A day out",
              marks: ["bold" as const],
            },
          ],
        },
        {
          type: "paragraph" as const,
          content: [
            { type: "text" as const, text: "With " },
            {
              type: "mention" as const,
              mention_id: "01AAAAAAAAAAAAAAAAAAAAAAAA",
              person_id: "person-1",
              label: "Grandad",
            },
          ],
        },
        { type: "horizontal_rule" as const },
      ],
    };
    let savedBody: unknown;
    server.use(
      http.get(
        "http://localhost:8082/api/families/mercer/stories/story-1",
        () =>
          HttpResponse.json({
            data: {
              id: "story-1",
              heading: "A day out",
              body: document,
              body_html: "<h2>A day out</h2><p>With Grandad</p><hr>",
              body_plain_text: "A day out\n\nWith Grandad",
              subject: { type: "person", id: "person-1", label: "Grandad" },
              hero: null,
              author: {
                id: 1,
                display_name: "David",
                person_id: null,
                initials: "D",
                portrait_thumbnail_url: null,
              },
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
      http.patch(
        "http://localhost:8082/api/families/mercer/stories/story-1",
        async ({ request }) => {
          const input = (await request.json()) as { body: unknown };
          savedBody = input.body;
          return HttpResponse.json({ data: { id: "story-1" } });
        },
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

    await userEvent.click(
      await screen.findByRole("button", { name: "Edit Story" }),
    );
    expect(screen.getByRole("separator")).toBeInTheDocument();
    await userEvent.click(screen.getByRole("button", { name: "Save Story" }));

    await waitFor(() => {
      expect(savedBody).toEqual(document);
    });
  });
});
