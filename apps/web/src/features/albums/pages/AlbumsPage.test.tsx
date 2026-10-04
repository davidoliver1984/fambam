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
  is_new: false,
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
  vi.mocked(getAlbums).mockResolvedValue({ items: [album], next_cursor: null });
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
  vi.unstubAllGlobals();
});

describe("AlbumsPage", () => {
  it("loads the next server page automatically near the scroll boundary without duplicate requests", async () => {
    let callback: IntersectionObserverCallback | undefined;
    let options: IntersectionObserverInit | undefined;
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(
          suppliedCallback: IntersectionObserverCallback,
          suppliedOptions?: IntersectionObserverInit,
        ) {
          callback = suppliedCallback;
          options = suppliedOptions;
        }
        observe() {}
        disconnect() {}
      },
    );
    const second = { ...album, id: "album-2", name: "Second page" };
    vi.mocked(getAlbums).mockImplementation((_familySlug, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [album], next_cursor: "next-page" }
          : { items: [second], next_cursor: null },
      ),
    );

    renderPage();
    await screen.findByText(album.name);
    expect(options?.rootMargin).toBe("400px 0px");
    callback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );
    callback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );

    expect(await screen.findByText("Second page")).toBeInTheDocument();
    expect(getAlbums).toHaveBeenCalledTimes(2);
    expect(vi.mocked(getAlbums).mock.calls[1]?.[2]).toBe("next-page");
  });

  it("offers a keyboard-accessible fallback when automatic observation is unavailable", async () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    const second = { ...album, id: "album-2", name: "Fallback page" };
    vi.mocked(getAlbums).mockImplementation((_familySlug, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [album], next_cursor: "next-page" }
          : { items: [second], next_cursor: null },
      ),
    );
    const user = userEvent.setup();

    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Load more albums" }),
    );

    expect(await screen.findByText("Fallback page")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Load more albums" }),
    ).not.toBeInTheDocument();
  });

  it("keeps loaded albums mounted and announces next-page loading", async () => {
    let callback: IntersectionObserverCallback | undefined;
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(suppliedCallback: IntersectionObserverCallback) {
          callback = suppliedCallback;
        }
        observe() {}
        disconnect() {}
      },
    );
    let resolveNextPage:
      ((page: { items: Album[]; next_cursor: null }) => void) | undefined;
    vi.mocked(getAlbums).mockImplementation((_familySlug, _criteria, cursor) =>
      cursor === null
        ? Promise.resolve({ items: [album], next_cursor: "next-page" })
        : new Promise((resolve) => {
            resolveNextPage = resolve;
          }),
    );

    renderPage();
    await screen.findByText(album.name);
    callback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );

    expect(await screen.findByText("Loading more albums…")).toHaveAttribute(
      "role",
      "status",
    );
    expect(screen.getByText(album.name)).toBeInTheDocument();
    resolveNextPage?.({
      items: [{ ...album, id: "album-2", name: "Second page" }],
      next_cursor: null,
    });
    expect(await screen.findByText("Second page")).toBeInTheDocument();
    await waitFor(() => {
      expect(
        screen.queryByText("Loading more albums…"),
      ).not.toBeInTheDocument();
    });
  });

  it("renders New only from the server-provided flag", async () => {
    vi.mocked(getAlbums).mockResolvedValue({
      items: [{ ...album, is_new: true }],
      next_cursor: null,
    });

    renderPage();

    expect(await screen.findByText("New")).toBeInTheDocument();
  });

  it("shows authorised thumbnails in the general Album listing", async () => {
    vi.mocked(getAlbums).mockResolvedValue({
      items: [
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
      ],
      next_cursor: null,
    });
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
    vi.mocked(getAlbums).mockResolvedValue({
      items: [
        { ...album, permissions: { can_manage: false, can_contribute: false } },
      ],
      next_cursor: null,
    });

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
