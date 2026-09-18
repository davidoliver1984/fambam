import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getDiscovery } from "../api/searchApi";
import { DiscoveryPage } from "./DiscoveryPage";

vi.mock("@/features/photos/components/PhotoPresentationImage", () => ({
  PhotoPresentationImage: () => <img alt="" src="/authorised-photo-preview" />,
}));

vi.mock("../api/searchApi", () => ({
  searchArchive: vi.fn(),
  getSearchSuggestions: vi.fn(),
  getDiscovery: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("DiscoveryPage", () => {
  it("renders the authorized related domain summaries with real links", async () => {
    vi.mocked(getDiscovery).mockResolvedValue({
      source: { type: "people", id: "person-1" },
      related: {
        photos: [
          {
            id: "photo-1",
            media_upload_id: "upload-1",
            caption: "Summer beach",
            description: null,
            location_description: null,
            historical_date: null,
            people: [{ id: "person-1", preferred_name: "David" }],
          },
        ],
        events: [
          {
            id: "event-1",
            name: "Beach holiday",
            description: null,
            location: "Cornwall",
            starts_on: "2026-08-01",
            ends_on: null,
          },
        ],
        stories: [
          {
            id: "story-1",
            heading: "About David",
            excerpt: "A family memory",
            subject: { type: "person", id: "person-1" },
            created_at: "2026-09-09T10:00:00Z",
          },
        ],
      },
    });
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/discover/:type/:id",
          element: <DiscoveryPage />,
        },
      ],
      {
        initialEntries: ["/families/family-archive/discover/people/person-1"],
      },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole("link", { name: "Summer beach" }),
    ).toHaveAttribute("href", "/families/family-archive/photos/photo-1");
    expect(screen.getByAltText("")).toHaveAttribute(
      "src",
      "/authorised-photo-preview",
    );
    expect(screen.getByRole("link", { name: "Beach holiday" })).toHaveAttribute(
      "href",
      "/families/family-archive/events/event-1",
    );
    expect(screen.getByText("About David")).toBeInTheDocument();
    expect(
      screen.getByRole("link", {
        name: /view the person this story is about/i,
      }),
    ).toHaveAttribute("href", "/families/family-archive/people/person-1");
  });
});
