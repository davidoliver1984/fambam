import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPhotoVersions } from "@/features/photos/api/photoEditorApi";

import { getAlbum, requestAlbumExport, setAlbumCover } from "../api/albumApi";
import { AlbumPage } from "./AlbumPage";

vi.mock("../api/albumApi", () => ({
  getAlbum: vi.fn(),
  requestAlbumExport: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
  setAlbumCover: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));
vi.mock("@/features/photos/api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("AlbumPage", () => {
  it("uses the authorized cover endpoint with a named Photo choice", async () => {
    vi.mocked(getAlbum).mockResolvedValue({
      id: "album-1",
      name: "Summer memories",
      description: null,
      visibility: "family_space",
      created_by: 1,
      creator: { id: 1, name: "Album creator" },
      created_at: "2026-09-01T10:00:00+00:00",
      updated_at: "2026-09-02T10:00:00+00:00",
      photo_count: 1,
      guest_participation: "none",
      photos: [
        {
          id: "photo-1",
          media_upload_id: "upload-1",
          caption: "Garden party",
          client_filename: "garden.jpg",
          visibility: "family_space",
          position: 1,
        },
      ],
      grants: [],
      permissions: { can_manage: true, can_contribute: false },
    });
    vi.mocked(getPhotoVersions).mockResolvedValue({
      active_photo_version_id: null,
      can_edit: false,
      versions: [],
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
    await userEvent
      .setup()
      .selectOptions(await screen.findByLabelText("Album cover"), "photo-1");
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Save cover" }));
    expect(setAlbumCover).toHaveBeenCalledWith("family-archive", "album-1", {
      photoId: "photo-1",
      confirmVisibilityWidening: false,
    });
  });

  it("renders ordered thumbnail cards that navigate to Photo detail", async () => {
    vi.mocked(requestAlbumExport).mockResolvedValue({ id: "export-1" });
    vi.mocked(getPhotoVersions).mockResolvedValue({
      active_photo_version_id: null,
      can_edit: false,
      versions: [],
    });
    vi.mocked(getAlbum).mockResolvedValue({
      id: "album-1",
      name: "Wedding photographs",
      description: null,
      visibility: "family_space",
      created_by: 1,
      creator: { id: 1, name: "Album creator" },
      created_at: "2026-09-01T10:00:00+00:00",
      updated_at: "2026-09-02T10:00:00+00:00",
      photo_count: 2,
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
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Export Album Photos" }));
    await waitFor(() => {
      expect(requestAlbumExport).toHaveBeenCalledWith(
        "family-archive",
        "album-1",
      );
    });
    expect(
      await screen.findByRole("link", { name: "View export status" }),
    ).toHaveAttribute("href", "/families/family-archive/exports");
  });

  it("shows an admitted contributor the scoped upload control and Event return path", async () => {
    vi.mocked(getAlbum).mockResolvedValue({
      id: "album-1",
      name: "Wedding photographs",
      description: null,
      visibility: "family_space",
      created_by: 1,
      creator: { id: 1, name: "Album creator" },
      created_at: "2026-09-01T10:00:00+00:00",
      updated_at: "2026-09-02T10:00:00+00:00",
      photo_count: 0,
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
      screen.getByLabelText("Add photographs to this Album"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Back to Family wedding" }),
    ).toHaveAttribute("href", "/families/family-archive/events/event-1");
  });
});
