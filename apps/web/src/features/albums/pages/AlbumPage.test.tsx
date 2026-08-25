import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getAlbum } from "../api/albumApi";
import { AlbumPage } from "./AlbumPage";

vi.mock("../api/albumApi", () => ({
  getAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("AlbumPage", () => {
  it("shows an admitted contributor the scoped upload control and Event return path", async () => {
    vi.mocked(getAlbum).mockResolvedValue({
      id: "album-1",
      name: "Wedding photographs",
      description: null,
      visibility: "family_space",
      created_by: 1,
      event_id: "event-1",
      event: { id: "event-1", name: "Family wedding", starts_on: "2026-08-25" },
      guest_participation: "contribute",
      photos: [],
      grants: [],
      permissions: { can_manage: false, can_contribute: true },
    });
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/albums/:albumId",
          element: <AlbumPage />,
        },
      ],
      { initialEntries: ["/families/family-archive/albums/album-1"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole("heading", { name: "Wedding photographs" }),
    ).toBeInTheDocument();
    expect(
      screen.getByLabelText("Add photographs to this Event"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Back to Family wedding" }),
    ).toHaveAttribute("href", "/families/family-archive/events/event-1");
  });
});
