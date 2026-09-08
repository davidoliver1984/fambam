import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { searchArchive } from "../api/searchApi";
import { SearchPage } from "./SearchPage";

vi.mock("../api/searchApi", () => ({ searchArchive: vi.fn() }));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("SearchPage", () => {
  it("searches each result group through the feature query and renders typed links", async () => {
    vi.mocked(searchArchive).mockImplementation((_family, group) => {
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
              event_id: null,
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
    await user.type(screen.getByLabelText("Words or names"), "beach");
    await user.click(screen.getByRole("button", { name: "Search archive" }));

    expect(
      (await screen.findAllByRole("link", { name: "Summer beach" }))[0],
    ).toHaveAttribute("href", "/families/family-archive/photos/photo-1");
    expect(screen.getByRole("link", { name: "Beach days" })).toHaveAttribute(
      "href",
      "/families/family-archive/albums/album-1",
    );
    expect(screen.getByText("A day by the sea")).toBeInTheDocument();
    expect(searchArchive).toHaveBeenCalledTimes(3);
  });
});
