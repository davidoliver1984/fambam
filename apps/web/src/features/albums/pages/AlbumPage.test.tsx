import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";

import { getAlbum } from "../api/albumApi";
import { AlbumPage } from "./AlbumPage";

vi.mock("../api/albumApi", () => ({
  getAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("AlbumPage", () => {
  it("renders ordered thumbnail cards that navigate to Photo detail", async () => {
    vi.mocked(getAlbum).mockResolvedValue({
      id: "album-1",
      name: "Wedding photographs",
      description: null,
      visibility: "family_space",
      created_by: 1,
      event_id: "event-1",
      event: { id: "event-1", name: "Family wedding", starts_on: null },
      guest_participation: "view",
      photos: [
        {
          id: "photo-1",
          media_upload_id: "upload-1",
          caption: "First dance",
          client_filename: "first.jpg",
          visibility: "family_space",
          position: 1,
        },
        {
          id: "photo-2",
          media_upload_id: "upload-2",
          caption: null,
          client_filename: "second.jpg",
          visibility: "family_space",
          position: 2,
        },
      ],
      grants: [],
      permissions: { can_manage: false, can_contribute: false },
    });
    vi.mocked(getMediaVariantDelivery).mockImplementation(
      (_familySlug, mediaUploadId) =>
        Promise.resolve({
          asset: "variant",
          transform_name: "thumbnail",
          processing_version: 1,
          url: `https://storage.test/${mediaUploadId}`,
          method: "GET",
          expires_at: "2026-08-10T12:05:00+00:00",
        }),
    );
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

    await waitFor(() => {
      expect(screen.getAllByRole("img")).toHaveLength(2);
    });
    const images = screen.getAllByRole("img");
    expect(images.map((image) => image.getAttribute("alt"))).toEqual([
      "First dance",
      "second.jpg",
    ]);
    expect(screen.getByRole("link", { name: /First dance/ })).toHaveAttribute(
      "href",
      "/families/family-archive/photos/photo-1?albumId=album-1&eventId=event-1",
    );
    expect(getMediaVariantDelivery).toHaveBeenCalledWith(
      "family-archive",
      "upload-1",
      "thumbnail",
      expect.any(AbortSignal),
    );
  });

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
