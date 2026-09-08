import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getSearchSuggestions, searchArchive } from "../api/searchApi";
import { SearchPage } from "./SearchPage";

vi.mock("@/features/family-spaces/hooks/useFamilySpaceQuery", () => ({
  useFamilySpaceQuery: () => ({
    data: {
      id: "family-1",
      slug: "family-archive",
      name: "Family archive",
      status: "active",
      role: "owner",
    },
  }),
}));
vi.mock("../api/searchApi", () => ({
  searchArchive: vi.fn(),
  getSearchSuggestions: vi.fn(),
  getDiscovery: vi.fn(),
  getSavedSearches: vi.fn().mockResolvedValue([]),
  createSavedSearch: vi.fn(),
  updateSavedSearch: vi.fn(),
  deleteSavedSearch: vi.fn(),
  runSavedSearch: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("SearchPage", () => {
  it("applies selected Person and Event filters and renders every typed group", async () => {
    vi.mocked(getSearchSuggestions).mockImplementation((_family, type) =>
      Promise.resolve(
        {
          people: [{ id: "person-1", label: "David" }],
          events: [{ id: "event-1", label: "Beach holiday" }],
          albums: [{ id: "album-1", label: "Beach days" }],
          tags: [{ id: "tag-1", label: "Summer" }],
          uploaders: [{ id: "42", label: "Alex" }],
        }[type],
      ),
    );
    vi.mocked(searchArchive).mockImplementation((_family, group) => {
      if (group === "people") {
        return Promise.resolve({
          items: [{ id: "person-1", preferred_name: "David" }],
          next_cursor: null,
        });
      }
      if (group === "photos") {
        return Promise.resolve({
          items: [
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
          next_cursor: null,
        });
      }
      if (group === "albums") {
        return Promise.resolve({
          items: [
            {
              id: "album-1",
              name: "Beach days",
              description: null,
              visibility: "family_space",
              event_id: "event-1",
            },
          ],
          next_cursor: null,
        });
      }
      if (group === "events") {
        return Promise.resolve({
          items: [
            {
              id: "event-1",
              name: "Beach holiday",
              description: null,
              location: "Cornwall",
              starts_on: "2026-08-01",
              ends_on: "2026-08-08",
            },
          ],
          next_cursor: null,
        });
      }
      return Promise.resolve({
        items: [
          {
            id: "story-1",
            photo_id: "photo-1",
            photo_caption: "Summer beach",
            media_upload_id: "upload-1",
            excerpt: "A day by the sea",
            created_at: "2026-09-08T10:00:00Z",
          },
        ],
        next_cursor: null,
      });
    });
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [{ path: "/families/:familySlug/search", element: <SearchPage /> }],
      { initialEntries: ["/families/family-archive/search"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    const user = userEvent.setup();
    await user.type(screen.getByLabelText("Find a Person"), "Dav");
    await user.click(await screen.findByRole("button", { name: "Add David" }));
    await user.type(screen.getByLabelText("Find an Event"), "Beach");
    await user.click(
      await screen.findByRole("button", { name: "Select Beach holiday" }),
    );
    await user.type(screen.getByLabelText("Find an Album"), "Beach");
    await user.click(
      await screen.findByRole("button", { name: "Select Beach days" }),
    );
    await user.type(screen.getByLabelText("Find a tag"), "Sum");
    await user.click(
      await screen.findByRole("button", { name: "Select Summer" }),
    );
    await user.type(screen.getByLabelText("Find an uploader"), "Ale");
    await user.click(
      await screen.findByRole("button", { name: "Select Alex" }),
    );
    await user.selectOptions(screen.getByLabelText("Visibility"), "selected");
    await user.click(screen.getByRole("button", { name: "Search archive" }));

    expect(
      (await screen.findAllByRole("link", { name: "Summer beach" }))[0],
    ).toHaveAttribute("href", "/families/family-archive/photos/photo-1");
    expect(screen.getByRole("link", { name: "Beach days" })).toHaveAttribute(
      "href",
      "/families/family-archive/albums/album-1",
    );
    expect(screen.getByRole("link", { name: "David" })).toHaveAttribute(
      "href",
      "/families/family-archive/people/person-1",
    );
    expect(screen.getByRole("link", { name: "Beach holiday" })).toHaveAttribute(
      "href",
      "/families/family-archive/events/event-1",
    );
    expect(screen.getByText("A day by the sea")).toBeInTheDocument();
    expect(searchArchive).toHaveBeenCalledTimes(5);
    expect(searchArchive).toHaveBeenCalledWith(
      "family-archive",
      "photos",
      {
        person_ids: ["person-1"],
        event_id: "event-1",
        album_id: "album-1",
        tag_id: "tag-1",
        uploaded_by: 42,
        visibility: "selected",
      },
      null,
      expect.any(AbortSignal),
    );
  });
});
