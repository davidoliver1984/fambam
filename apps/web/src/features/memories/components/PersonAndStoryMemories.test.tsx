import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { PersonAndStoryMemories } from "@/features/memories/components/PersonAndStoryMemories";
import { server } from "@/test/msw/server";

const apiBaseUrl = "http://localhost:8082";
const memoriesEndpoint = `${apiBaseUrl}/api/families/mercer-family/memories/homepage`;

function renderMemories() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/",
        element: <PersonAndStoryMemories familySlug="mercer-family" />,
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

describe("PersonAndStoryMemories", () => {
  it("links recent Stories through their actual typed subjects", async () => {
    server.use(
      http.get(memoriesEndpoint, () =>
        HttpResponse.json({
          data: {
            recent_days: 30,
            people: [
              {
                person_id: "person-1",
                preferred_name: "William Mercer",
                memory_count: 3,
                latest_at: "2026-09-09T10:00:00+00:00",
              },
            ],
            stories: [
              {
                id: "story-1",
                heading: "At the seaside",
                excerpt: "The water was freezing.",
                subject: { type: "photo", id: "photo-1" },
                created_at: "2026-09-09T10:00:00+00:00",
                author: { id: 10, name: "David" },
                people: [{ id: "person-1", name: "William Mercer" }],
                albums: [{ id: "album-1", name: "Summer memories" }],
                events: [{ id: "event-1", name: "Seaside holiday" }],
              },
              {
                id: "story-2",
                heading: "The family historian",
                excerpt: "A few words about William.",
                subject: { type: "person", id: "person-1" },
                created_at: "2026-09-09T09:00:00+00:00",
                author: { id: 10, name: "David" },
                people: [],
                albums: [],
                events: [],
              },
            ],
          },
        }),
      ),
    );

    renderMemories();

    expect(
      await screen.findByRole("link", { name: /more from william mercer/i }),
    ).toHaveAttribute(
      "href",
      "/families/mercer-family/discover/people/person-1",
    );
    expect(await screen.findByText("At the seaside")).toBeInTheDocument();
    expect(screen.getAllByText("Story by David")).toHaveLength(2);
    expect(screen.getByText("People: William Mercer")).toBeInTheDocument();
    expect(screen.getByText("Albums: Summer memories")).toBeInTheDocument();
    expect(screen.getByText("Events: Seaside holiday")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /view the photo this story is about/i }),
    ).toHaveAttribute("href", "/families/mercer-family/photos/photo-1");
    expect(
      screen.getByRole("link", {
        name: /view the person this story is about/i,
      }),
    ).toHaveAttribute("href", "/families/mercer-family/people/person-1");
  });

  it("renders loading, empty and error states without disclosing partial content", async () => {
    server.use(
      http.get(memoriesEndpoint, () =>
        HttpResponse.json({
          data: { recent_days: 30, people: [], stories: [] },
        }),
      ),
    );
    renderMemories();
    expect(screen.getByRole("status")).toHaveTextContent(/finding recent/i);
    expect(
      await screen.findByText(/no new person-centred memories/i),
    ).toBeInTheDocument();
    expect(screen.getByText(/no Stories have been added/i)).toBeInTheDocument();
    cleanup();

    server.use(
      http.get(memoriesEndpoint, () => HttpResponse.json({}, { status: 403 })),
    );
    renderMemories();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      /could not be loaded/i,
    );
    expect(screen.queryByText("At the seaside")).not.toBeInTheDocument();
  });
});
