import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getFamilySpace } from "@/features/family-spaces/api/familySpaceApi";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPhotoVersions } from "@/features/photos/api/photoEditorApi";
import { getPhotos } from "@/features/photos/api/photoApi";
import type { Photo } from "@/features/photos/types/photo";
import {
  addPhotoToAlbum,
  createAlbum,
  getAlbums,
  removePhotoFromAlbum,
  uploadPhotoToAlbum,
} from "../api/albumApi";
import type { Album } from "../types/album";
import { AlbumsPage } from "./AlbumsPage";

vi.mock("../api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  createAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));
vi.mock("@/features/family-spaces/api/familySpaceApi", () => ({
  getFamilySpace: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));
vi.mock("@/features/photos/api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
}));
vi.mock("@/features/photos/api/photoApi", () => ({ getPhotos: vi.fn() }));

const album: Album = {
  id: "01K80000000000000000000000",
  name: "Selected memories",
  description: null,
  visibility: "selected",
  created_by: 1,
  creator: { id: 1, name: "Album creator" },
  created_at: "2026-09-01T10:00:00+00:00",
  updated_at: "2026-09-02T10:00:00+00:00",
  photo_count: 0,
  event_id: "01KB0000000000000000000000",
  event: {
    id: "01KB0000000000000000000000",
    name: "Family wedding",
    starts_on: "2026-08-25",
  },
  guest_participation: "none",
  photos: [],
  grants: [],
  permissions: { can_manage: false, can_contribute: true },
};

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/albums", element: <AlbumsPage /> }],
    {
      initialEntries: ["/families/family-archive/albums"],
    },
  );
  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: false,
    versions: [],
  });
  vi.mocked(getAlbums).mockResolvedValue([album]);
  vi.mocked(getPhotos).mockResolvedValue([]);
  vi.mocked(getFamilySpace).mockResolvedValue({
    id: "01K90000000000000000000000",
    slug: "family-archive",
    name: "Family Archive",
    status: "active",
    role: "contributor",
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("AlbumsPage", () => {
  it("shows authorised thumbnails in the general Album listing", async () => {
    vi.mocked(getAlbums).mockResolvedValue([
      {
        ...album,
        photos: [
          {
            id: "photo-1",
            media_upload_id: "upload-1",
            caption: "Family wedding",
            client_filename: "wedding.jpg",
            visibility: "family_space",
            position: 1,
          },
        ],
      },
    ]);
    vi.mocked(getMediaVariantDelivery).mockResolvedValue({
      asset: "variant",
      transform_name: "thumbnail",
      processing_version: 1,
      url: "https://storage.test/signed-thumbnail",
      method: "GET",
      expires_at: "2026-08-10T12:05:00+00:00",
    });

    renderPage();

    await waitFor(() => {
      expect(
        screen.getByRole("img", { name: "Family wedding" }),
      ).toHaveAttribute("src", "https://storage.test/signed-thumbnail");
    });
    expect(
      screen.getByRole("link", { name: /Family wedding/ }),
    ).toHaveAttribute(
      "href",
      `/families/family-archive/photos/photo-1?albumId=${album.id}&eventId=${album.event?.id ?? ""}`,
    );
  });

  it("shows scoped Event Album contribution controls without offering Contributor album creation", async () => {
    renderPage();
    expect(await screen.findByText("Selected memories")).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "Create an Album" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByLabelText("Upload a new Photo to this Album"),
    ).toBeInTheDocument();
    expect(screen.queryByLabelText("Photo ID")).not.toBeInTheDocument();
    expect(
      screen.queryByLabelText("Choose a Photo already in the archive"),
    ).not.toBeInTheDocument();
  });

  it("does not offer Guest a Family Space Album creation action", async () => {
    vi.mocked(getFamilySpace).mockResolvedValue({
      id: "01K90000000000000000000000",
      slug: "family-archive",
      name: "Family Archive",
      status: "active",
      role: "guest",
    });
    vi.mocked(getAlbums).mockResolvedValue([
      { ...album, permissions: { can_manage: false, can_contribute: false } },
    ]);

    renderPage();

    expect(await screen.findByText("Selected memories")).toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Create album" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "Create an Album" }),
    ).not.toBeInTheDocument();
  });

  it("passes explicit widening confirmation when adding an existing Photo", async () => {
    const user = userEvent.setup();
    vi.mocked(getFamilySpace).mockResolvedValue({
      id: "01K90000000000000000000000",
      slug: "family-archive",
      name: "Family Archive",
      status: "active",
      role: "member",
    });
    vi.mocked(getPhotos).mockResolvedValue([
      {
        id: "01KA0000000000000000000000",
        caption: "Family picnic",
        media_upload: { client_filename: "picnic.jpg" },
      } as Photo,
    ]);
    renderPage();
    await screen.findByText("Selected memories");
    await user.selectOptions(
      await screen.findByLabelText("Choose a Photo already in the archive"),
      "01KA0000000000000000000000",
    );
    await user.click(screen.getByRole("button", { name: "Add Photo" }));
    expect(addPhotoToAlbum).toHaveBeenCalledWith(
      "family-archive",
      album.id,
      "01KA0000000000000000000000",
      true,
    );
    expect(createAlbum).not.toHaveBeenCalled();
    expect(removePhotoFromAlbum).not.toHaveBeenCalled();
    expect(uploadPhotoToAlbum).not.toHaveBeenCalled();
  });
});
