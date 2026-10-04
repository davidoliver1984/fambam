import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import userEvent from "@testing-library/user-event";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPhotoVersions } from "@/features/photos/api/photoEditorApi";

import {
  deleteAlbum,
  getAlbum,
  getAlbums,
  requestAlbumExport,
  setAlbumCover,
  updateAlbum,
} from "../api/albumApi";
import type { Album } from "../types/album";
import { AlbumPage } from "./AlbumPage";

vi.mock("../api/albumApi", () => ({
  getAlbum: vi.fn(),
  getAlbums: vi.fn(),
  requestAlbumExport: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
  setAlbumCover: vi.fn(),
  updateAlbum: vi.fn(),
  deleteAlbum: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));
vi.mock("@/features/photos/api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
}));
vi.mock("@/features/love/components/LoveButton", () => ({
  LoveButton: () => <button type="button">Love this · 0</button>,
}));
vi.mock("@/features/events/components/EventPhotoTile", () => ({
  PhotoTileStats: () => <button type="button">Love photo</button>,
  PhotoTileMenu: () => <button type="button" aria-label="Photo options" />,
}));
vi.mock("@/features/events/components/CollectionPickerDialog", () => ({
  CollectionPickerDialog: () => null,
}));
vi.mock("@/features/search/hooks/useArchiveSearchQuery", () => ({
  useArchiveSearchQuery: () => ({ data: { pages: [{ items: [] }] } }),
  useSearchSuggestionsQuery: () => ({
    data: [],
    isError: false,
  }),
}));

beforeEach(() => {
  vi.mocked(getAlbums).mockResolvedValue({ items: [], next_cursor: null });
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
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function albumFixture(
  input: Omit<
    Album,
    "creator" | "created_at" | "updated_at" | "is_new" | "photo_count"
  >,
): Album {
  return {
    creator: null,
    created_at: "2026-09-25T10:00:00+00:00",
    updated_at: "2026-09-25T10:00:00+00:00",
    is_new: false,
    photo_count: input.photos.length,
    ...input,
  };
}

describe("AlbumPage", () => {
  it("uses the authorized cover endpoint with a named Photo choice", async () => {
    vi.mocked(getAlbum).mockResolvedValue(
      albumFixture({
        id: "album-1",
        name: "Summer memories",
        description: null,
        visibility: "family_space",
        created_by: 1,
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
        permissions: {
          can_manage: true,
          can_contribute: false,
          can_delete: true,
        },
      }),
    );
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
      .click(await screen.findByRole("button", { name: "Add cover photo" }));
    await userEvent
      .setup()
      .click(screen.getByRole("menuitem", { name: "Add cover photo" }));
    await userEvent
      .setup()
      .click(screen.getByRole("radio", { name: "Garden party" }));
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Use as cover" }));
    expect(setAlbumCover).toHaveBeenCalledWith("family-archive", "album-1", {
      photoId: "photo-1",
      confirmVisibilityWidening: false,
    });
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Album options" }));
    expect(
      screen.getByRole("menuitem", { name: "Delete album" }),
    ).toBeInTheDocument();
  });

  it("renders ordered thumbnail cards that navigate to Photo detail", async () => {
    vi.mocked(requestAlbumExport).mockResolvedValue({ id: "export-1" });
    vi.mocked(getPhotoVersions).mockResolvedValue({
      active_photo_version_id: null,
      can_edit: false,
      versions: [],
    });
    vi.mocked(getAlbum).mockResolvedValue(
      albumFixture({
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
        permissions: {
          can_manage: false,
          can_contribute: false,
          can_delete: false,
        },
      }),
    );
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
      expect(document.querySelectorAll(".album-photo-thumbnail")).toHaveLength(
        2,
      );
    });
    expect(
      screen.getByRole("link", { name: "Open First dance" }),
    ).toHaveAttribute(
      "href",
      "/families/family-archive/photos/photo-1?albumId=album-1&eventId=event-1",
    );
    expect(
      screen.getAllByRole("button", { name: "Love photo" })[0]?.closest("a"),
    ).toBeNull();
    await waitFor(() => {
      expect(getMediaVariantDelivery).toHaveBeenCalledWith(
        "family-archive",
        "upload-1",
        "thumbnail",
        expect.any(AbortSignal),
      );
    });
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Album options" }));
    expect(
      screen.queryByRole("menuitem", { name: "Delete album" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("menuitem", { name: "Manage tags" }),
    ).not.toBeInTheDocument();
    await userEvent
      .setup()
      .click(screen.getByRole("menuitem", { name: "Download / Export album" }));
    await waitFor(() => {
      expect(requestAlbumExport).toHaveBeenCalledWith(
        "family-archive",
        "album-1",
      );
    });
    expect(await screen.findByRole("status")).toHaveTextContent(
      "Album export startedOnly Photos you are authorised to download are included.",
    );
  });

  it("adds an Album tag from the compact inline field", async () => {
    const album = albumFixture({
      id: "album-1",
      name: "Summer memories",
      description: null,
      visibility: "family_space" as const,
      created_by: 1,
      guest_participation: "none" as const,
      photos: [],
      grants: [],
      tags: [{ id: "tag-1", label: "Seaside" }],
      people: [
        { id: "person-1", name: "William Mercer" },
        { id: "person-2", name: "Jane Mercer" },
        { id: "person-3", name: "Robert Mercer" },
        { id: "person-4", name: "Margaret Shaw" },
        { id: "person-5", name: "James Mercer" },
        { id: "person-6", name: "Sarah Mercer" },
      ],
      permissions: {
        can_manage: true,
        can_contribute: false,
        can_delete: true,
      },
    });
    vi.mocked(getAlbum).mockResolvedValue(album);
    vi.mocked(updateAlbum).mockResolvedValue({
      ...album,
      tags: [...(album.tags ?? []), { id: "tag-2", label: "Blackpool" }],
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
      await screen.findByRole("button", { name: "Remove Seaside tag" }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("link", { name: "James Mercer" })).toBeNull();
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "and 2 others" }));
    expect(
      screen.getByRole("link", { name: "James Mercer" }),
    ).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "and 2 others" })).toBeNull();

    const addTag = screen.getByRole("button", { name: "Add tag" });
    await userEvent.setup().click(addTag);
    const input = screen.getByRole("textbox", { name: "Add or reuse a tag" });
    expect(input).toHaveFocus();
    await userEvent.setup().type(input, "Blackpool{Enter}");

    await waitFor(() => {
      expect(updateAlbum).toHaveBeenCalledWith("family-archive", "album-1", {
        tags: ["Seaside", "Blackpool"],
      });
    });
    expect(
      screen.queryByRole("textbox", { name: "Add or reuse a tag" }),
    ).not.toBeInTheDocument();

    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Remove Seaside tag" }));
    await waitFor(() => {
      expect(updateAlbum).toHaveBeenLastCalledWith(
        "family-archive",
        "album-1",
        { tags: [] },
      );
    });
  });

  it("uses the canonical delete mutation and navigates only after success", async () => {
    vi.mocked(getAlbum).mockResolvedValue(
      albumFixture({
        id: "album-1",
        name: "Summer memories",
        description: null,
        visibility: "family_space",
        created_by: 1,
        guest_participation: "none",
        photos: [],
        grants: [],
        permissions: {
          can_manage: true,
          can_contribute: false,
          can_delete: true,
        },
      }),
    );
    let finishDelete: (() => void) | undefined;
    vi.mocked(deleteAlbum).mockReturnValue(
      new Promise<void>((resolve) => {
        finishDelete = resolve;
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
        {
          path: "/families/:familySlug/albums",
          element: <h1>Albums index</h1>,
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
      .click(await screen.findByRole("button", { name: "Album options" }));
    await userEvent
      .setup()
      .click(screen.getByRole("menuitem", { name: "Delete album" }));
    await userEvent
      .setup()
      .click(screen.getByRole("button", { name: "Delete album" }));

    expect(deleteAlbum).toHaveBeenCalledWith("family-archive", "album-1");
    expect(
      screen.getByRole("heading", { name: "Summer memories" }),
    ).toBeInTheDocument();

    finishDelete?.();
    expect(
      await screen.findByRole("heading", { name: "Albums index" }),
    ).toBeInTheDocument();
  });

  it("shows an admitted contributor scoped upload and Story controls", async () => {
    vi.mocked(getAlbum).mockResolvedValue(
      albumFixture({
        id: "album-1",
        name: "Wedding photographs",
        description: null,
        visibility: "family_space",
        created_by: 1,
        event_id: "event-1",
        event: {
          id: "event-1",
          name: "Family wedding",
          starts_on: "2026-08-25",
        },
        guest_participation: "contribute",
        photos: [],
        grants: [],
        permissions: {
          can_manage: false,
          can_contribute: true,
          can_delete: false,
        },
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
        {
          path: "/families/:familySlug/albums/:albumId/uploads",
          element: <p>Album upload destination</p>,
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
      screen.getAllByRole("button", { name: "Add photos" }).length,
    ).toBeGreaterThan(0);
    expect(screen.getByRole("link", { name: "Write story" })).toHaveAttribute(
      "href",
      "/families/family-archive/stories/new?type=album&subjectId=album-1",
    );
    await userEvent
      .setup()
      .click(screen.getAllByRole("button", { name: "Add photos" })[0]);
    expect(await screen.findByText("Album upload destination")).toBeVisible();
  });
});
