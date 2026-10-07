import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  addPhotoToAlbum,
  getAlbums,
  removePhotoFromAlbum,
} from "@/features/albums/api/albumApi";
import {
  addCollectionPhoto,
  createCollection,
  getCollections,
} from "@/features/collections/api/collectionApi";
import {
  getMediaVariantDelivery,
  getOriginalMediaDelivery,
} from "@/features/media-uploads/api/mediaUploadApi";

import { getPhotoVersions } from "../api/photoEditorApi";
import {
  getPhotoConversation,
  savePhotoReaction,
} from "../api/photoConversationApi";
import { getPhotoAlbumHistory, getPhotos } from "../api/photoApi";
import type { Photo } from "../types/photo";
import { PhotosPage } from "./PhotosPage";

vi.mock("../api/photoApi", () => ({
  getPhotoAlbumHistory: vi.fn(),
  getPhotos: vi.fn(),
}));
vi.mock("../api/photoEditorApi", () => ({ getPhotoVersions: vi.fn() }));
vi.mock("../api/photoConversationApi", async (importOriginal) => ({
  ...(await importOriginal<typeof import("../api/photoConversationApi")>()),
  getPhotoConversation: vi.fn(),
  savePhotoReaction: vi.fn(),
  removePhotoReaction: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
  getOriginalMediaDelivery: vi.fn(),
}));
vi.mock("@/features/albums/api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
}));
vi.mock("@/features/collections/api/collectionApi", () => ({
  addCollectionPhoto: vi.fn(),
  createCollection: vi.fn(),
  getCollections: vi.fn(),
}));

const photo: Photo = {
  id: "01K60000000000000000000000",
  media_upload: {
    id: "01K50000000000000000000000",
    client_filename: "family.jpg",
    uploader: { id: 1, name: "David" },
  },
  created_by: 1,
  visibility: "private",
  caption: "Family picnic",
  description: "A summer afternoon",
  archive_source_description: null,
  historical_date: { precision: "exact", value: "1986-08-18" },
  location_description: "Blackpool",
  do_not_resurface: false,
  love_count: 7,
  comment_count: 3,
  album_count: 0,
  interaction_album_id: null,
  viewer_has_loved: false,
  interaction_can_interact: false,
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Holiday" }],
  people: [
    {
      id: "01K80000000000000000000000",
      photo_id: "01K60000000000000000000000",
      person: {
        id: "01K90000000000000000000000",
        preferred_name: "William Mercer",
      },
      proposal_source: "manual",
      status: "approved",
      proposed_by: 1,
      resolved_by: 1,
      resolved_at: "2026-08-24T10:00:00Z",
      created_at: "2026-08-24T10:00:00Z",
    },
  ],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
    can_flag_duplicate: false,
  },
};

const album = {
  id: "01KA0000000000000000000000",
  name: "Blackpool, 1986",
  description: null,
  visibility: "family_space" as const,
  created_by: 1,
  creator: { id: 1, name: "David" },
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  is_new: false,
  photo_count: 0,
  guest_participation: "none" as const,
  photos: [],
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      { path: "/families/:familySlug/photos", element: <PhotosPage /> },
      { path: "/families/:familySlug/uploads", element: <p>Upload route</p> },
      {
        path: "/families/:familySlug/photos/:photoId",
        element: <p>Photo detail</p>,
      },
    ],
    { initialEntries: ["/families/oliver-family/photos"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(savePhotoReaction).mockResolvedValue(undefined);
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: true,
    versions: [],
  });
  vi.mocked(getMediaVariantDelivery).mockResolvedValue({
    asset: "variant",
    transform_name: "card",
    processing_version: 1,
    url: "https://storage.test/signed-card",
    method: "GET",
    expires_at: "2026-09-26T20:00:00Z",
  });
  vi.mocked(getOriginalMediaDelivery).mockResolvedValue({
    asset: "original",
    url: "https://storage.test/signed-original",
    method: "GET",
    expires_at: "2026-09-26T20:00:00Z",
  });
  vi.mocked(getPhotos).mockImplementation((_familySlug, criteria) =>
    Promise.resolve({
      items: criteria?.q === "missing" ? [] : [photo],
      next_cursor: null,
    }),
  );
  vi.mocked(getAlbums).mockResolvedValue({ items: [album], next_cursor: null });
  vi.mocked(getPhotoAlbumHistory).mockResolvedValue([]);
  vi.mocked(getCollections).mockResolvedValue([]);
  vi.mocked(addPhotoToAlbum).mockResolvedValue(undefined);
  vi.mocked(removePhotoFromAlbum).mockResolvedValue(undefined);
  vi.mocked(addCollectionPhoto).mockResolvedValue({
    id: "01KB0000000000000000000000",
    name: "Prints",
    description: null,
    purpose: null,
    created_at: "2026-09-26T10:00:00Z",
    updated_at: "2026-09-26T10:00:00Z",
    photo_count: 1,
    preview_photo: null,
  });
  vi.mocked(createCollection).mockResolvedValue({
    id: "01KB0000000000000000000000",
    name: "Prints",
    description: null,
    purpose: null,
    created_at: "2026-09-26T10:00:00Z",
    updated_at: "2026-09-26T10:00:00Z",
    photo_count: 0,
    preview_photo: null,
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("PhotosPage", () => {
  it("renders the approved archive card with authorized presentation data and aggregate engagement", async () => {
    renderPage();
    const image = await screen.findByRole("img", { name: "Family picnic" });
    expect(image).toHaveAttribute("src", "https://storage.test/signed-card");
    expect(image).toHaveAttribute("loading", "lazy");
    expect(image).toHaveAttribute("decoding", "async");
    expect(screen.getByRole("button", { name: "Love · 7" })).toBeDisabled();
    expect(screen.getByRole("link", { name: "3 comments" })).toHaveAttribute(
      "href",
      "/families/oliver-family/photos/01K60000000000000000000000",
    );
    expect(screen.getAllByText("Not in an album")).toHaveLength(2);
    expect(getPhotos).toHaveBeenCalledWith(
      "oliver-family",
      { sort: "newest" },
      null,
      expect.any(AbortSignal),
    );
  });

  it("uses the server-selected Album context for Love without fetching each conversation", async () => {
    vi.mocked(getPhotos).mockResolvedValue({
      items: [
        {
          ...photo,
          album_count: 2,
          interaction_album_id: album.id,
          viewer_has_loved: false,
          interaction_can_interact: true,
        },
      ],
      next_cursor: null,
    });
    const user = userEvent.setup();
    renderPage();

    const detailLinks = await screen.findAllByRole("link", {
      name: /Family picnic/,
    });
    detailLinks.forEach((link) => {
      expect(link).toHaveAttribute(
        "href",
        `/families/oliver-family/photos/${photo.id}`,
      );
    });

    await user.click(screen.getByRole("button", { name: "Love · 7" }));

    await waitFor(() => {
      expect(savePhotoReaction).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
        "love",
        album.id,
      );
    });
    expect(getPhotoConversation).not.toHaveBeenCalled();
    expect(
      screen.getByRole("button", { name: "Remove love · 8" }),
    ).toHaveAttribute("aria-pressed", "true");
  });

  it("opens Photo detail and the real Add photos route", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    const links = screen.getAllByRole("link", { name: /Family picnic/ });
    expect(links[0]).toHaveAttribute(
      "href",
      `/families/oliver-family/photos/${photo.id}`,
    );
    await user.click(screen.getByRole("link", { name: "Add photos" }));
    expect(await screen.findByText("Upload route")).toBeInTheDocument();
  });

  it("searches, sorts and switches between grid and list views", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    await user.type(
      screen.getByRole("textbox", { name: "Search photographs…" }),
      "missing",
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { q: "missing", sort: "newest" },
        null,
        expect.any(AbortSignal),
      );
    });
    await user.keyboard("{Enter}");
    expect(screen.queryByText("Suggestions")).not.toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "No matches found" }),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Clear search and filters" }),
    );
    await user.click(screen.getByRole("button", { name: "Sort photographs" }));
    await user.click(
      screen.getByRole("menuitemradio", { name: "Oldest first" }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { sort: "oldest" },
        null,
        expect.any(AbortSignal),
      );
    });
    await user.click(screen.getByRole("button", { name: "Sort photographs" }));
    await user.click(
      screen.getByRole("menuitemradio", { name: "Recently added" }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { sort: "recently_added" },
        null,
        expect.any(AbortSignal),
      );
    });
    await user.click(screen.getByRole("button", { name: "List view" }));
    expect(screen.getByRole("button", { name: "List view" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
  });

  it("closes search suggestions when the search value is cleared", async () => {
    const user = userEvent.setup();
    renderPage();
    const search = await screen.findByRole("textbox", {
      name: "Search photographs…",
    });

    await user.type(search, "Blackpool");
    expect(screen.getByText("Suggestions")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Clear search" }));

    expect(screen.queryByText("Suggestions")).not.toBeInTheDocument();
    expect(search).toHaveValue("");
  });

  it("uses the canonical server-side Not in an album filter", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    await user.click(screen.getByRole("button", { name: "Filters" }));
    await user.click(
      screen.getByRole("button", { name: "Album status: Not in an album" }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { sort: "newest", without_album: true },
        null,
        expect.any(AbortSignal),
      );
    });
    expect(
      screen.getByRole("button", { name: /^Not in an album$/ }),
    ).toBeInTheDocument();
  });

  it("sends structured date, location and tag filters to the server", async () => {
    const user = userEvent.setup();
    renderPage();
    const search = await screen.findByRole("textbox", {
      name: "Search photographs…",
    });

    await user.type(search, "1986");
    await user.click(
      screen.getByRole("button", {
        name: "Filter by Historical year: 1986",
      }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { historical_year: "1986", sort: "newest" },
        null,
        expect.any(AbortSignal),
      );
    });
    await user.click(screen.getByRole("button", { name: "1986" }));

    await user.type(search, "Blackpool");
    await user.click(
      screen.getByRole("button", {
        name: "Filter by Location: Blackpool",
      }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { location: "Blackpool", sort: "newest" },
        null,
        expect.any(AbortSignal),
      );
    });
    await user.click(screen.getByRole("button", { name: "Blackpool" }));

    await user.type(search, "Holiday");
    await user.click(
      screen.getByRole("button", { name: "Filter by Tag: Holiday" }),
    );
    await waitFor(() => {
      expect(getPhotos).toHaveBeenLastCalledWith(
        "oliver-family",
        { sort: "newest", tag: "Holiday" },
        null,
        expect.any(AbortSignal),
      );
    });
  });

  it("automatically appends the next cursor page without duplicating Photo IDs", async () => {
    const user = userEvent.setup();
    let callback: IntersectionObserverCallback | undefined;
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor(suppliedCallback: IntersectionObserverCallback) {
          callback = suppliedCallback;
        }
        observe() {}
        disconnect() {}
        unobserve() {}
        takeRecords() {
          return [];
        }
        readonly root = null;
        readonly rootMargin = "400px 0px";
        readonly thresholds = [0];
      },
    );
    const secondPhoto: Photo = {
      ...photo,
      id: "01K60000000000000000000001",
      caption: "Nan at the seaside",
      media_upload: {
        ...photo.media_upload,
        id: "01K50000000000000000000001",
      },
    };
    vi.mocked(getPhotos).mockImplementation((_familySlug, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [photo], next_cursor: "page-2" }
          : { items: [photo, secondPhoto], next_cursor: null },
      ),
    );
    vi.mocked(getCollections).mockResolvedValue([
      {
        id: "01KB0000000000000000000000",
        name: "Prints",
        description: null,
        purpose: null,
        created_at: "2026-09-26T10:00:00Z",
        updated_at: "2026-09-26T10:00:00Z",
        photo_count: 0,
        preview_photo: null,
      },
    ]);
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    expect(callback).toBeDefined();
    callback?.(
      [{ isIntersecting: true } as IntersectionObserverEntry],
      {} as IntersectionObserver,
    );
    expect(
      await screen.findByRole("img", { name: "Nan at the seaside" }),
    ).toBeInTheDocument();
    expect(screen.getAllByRole("img", { name: "Family picnic" })).toHaveLength(
      1,
    );
    expect(getPhotos).toHaveBeenLastCalledWith(
      "oliver-family",
      { sort: "newest" },
      "page-2",
      expect.any(AbortSignal),
    );
    expect(screen.getByText("All photographs loaded")).toBeInTheDocument();

    await user.click(
      screen.getAllByRole("button", { name: "Photo options" })[1],
    );
    await user.click(
      screen.getByRole("menuitem", { name: "Add to collection…" }),
    );
    await user.click(await screen.findByRole("button", { name: "Add Photo" }));
    await waitFor(() => {
      expect(addCollectionPhoto).toHaveBeenCalledWith(
        "oliver-family",
        "01KB0000000000000000000000",
        secondPhoto.id,
      );
    });
  });

  it("offers an accessible continuation fallback when observation is unavailable", async () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    vi.mocked(getPhotos).mockResolvedValue({
      items: [photo],
      next_cursor: "page-2",
    });
    renderPage();
    expect(
      await screen.findByRole("button", { name: "Load more photographs" }),
    ).toBeInTheDocument();
  });

  it("offers the continuation fallback when observer setup fails", async () => {
    vi.stubGlobal(
      "IntersectionObserver",
      class {
        constructor() {
          throw new Error("observer unavailable");
        }
        observe() {}
      },
    );
    vi.mocked(getPhotos).mockResolvedValue({
      items: [photo],
      next_cursor: "page-2",
    });

    renderPage();

    expect(
      await screen.findByRole("button", { name: "Load more photographs" }),
    ).toBeInTheDocument();
  });

  it("shows the exact index menu without Delete and opens the Album picker", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    expect(getAlbums).not.toHaveBeenCalled();
    expect(getPhotoAlbumHistory).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Photo options" }));
    const menu = screen.getByRole("menu");
    expect(within(menu).getByText("Add to album…")).toBeInTheDocument();
    expect(within(menu).getByText("Add to collection…")).toBeInTheDocument();
    expect(within(menu).getByText("Edit photo")).toBeInTheDocument();
    expect(within(menu).getByText("Edit details")).toBeInTheDocument();
    expect(
      within(menu).getByText("Identify / Review people"),
    ).toBeInTheDocument();
    expect(within(menu).getByText("Download original")).toBeInTheDocument();
    expect(within(menu).getByText("Copy Fambam link")).toBeInTheDocument();
    expect(within(menu).queryByText("Delete Photo")).not.toBeInTheDocument();
    expect(
      within(menu).getByRole("menuitem", { name: "Edit details" }),
    ).toHaveAttribute(
      "href",
      `/families/oliver-family/photos/${photo.id}#edit-photo-title`,
    );
    expect(
      within(menu).getByRole("menuitem", {
        name: "Identify / Review people",
      }),
    ).toHaveAttribute(
      "href",
      `/families/oliver-family/photos/review-people?photo_id=${photo.id}`,
    );
    await user.click(within(menu).getByText("Add to album…"));
    expect(
      await screen.findByRole("dialog", { name: "Add to album" }),
    ).toBeInTheDocument();
    await waitFor(() => {
      expect(getPhotoAlbumHistory).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
        expect.any(AbortSignal),
      );
      expect(getAlbums).toHaveBeenCalledTimes(1);
    });
    expect(screen.getByLabelText("Add to Blackpool, 1986")).toBeInTheDocument();
    await user.click(screen.getByLabelText("Add to Blackpool, 1986"));
    await user.click(
      screen.getByRole("button", { name: "Save album changes" }),
    );
    await waitFor(() => {
      expect(addPhotoToAlbum).toHaveBeenCalledWith(
        "oliver-family",
        album.id,
        photo.id,
        false,
      );
    });
  });

  it("loads authoritative membership on demand before removing from an Album", async () => {
    const albumPhoto = { ...photo, album_count: 1 };
    vi.mocked(getPhotos).mockResolvedValue({
      items: [albumPhoto],
      next_cursor: null,
    });
    vi.mocked(getPhotoAlbumHistory).mockResolvedValue([
      {
        event_type: "added",
        album: { id: album.id, name: album.name },
        actor: {
          display_name: "David",
          person_id: null,
          initials: "D",
          portrait_thumbnail_url: null,
        },
        created_at: "2026-09-26T10:00:00Z",
        is_current: true,
      },
    ]);
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });
    expect(getPhotoAlbumHistory).not.toHaveBeenCalled();
    await user.click(screen.getByRole("button", { name: "Photo options" }));
    await user.click(
      screen.getByRole("menuitem", { name: "Remove from album…" }),
    );
    expect(
      await screen.findByRole("dialog", {
        name: "Remove this Photo from Blackpool, 1986?",
      }),
    ).toBeInTheDocument();
    expect(getPhotoAlbumHistory).toHaveBeenCalledTimes(1);
    await user.click(screen.getByRole("button", { name: "Remove from album" }));
    await waitFor(() => {
      expect(removePhotoFromAlbum).toHaveBeenCalledWith(
        "oliver-family",
        album.id,
        photo.id,
      );
    });
  });

  it("wires Collection, editor, original download and copy-link actions", async () => {
    const user = userEvent.setup();
    const writeText = vi.fn().mockResolvedValue(undefined);
    Object.defineProperty(navigator, "clipboard", {
      configurable: true,
      value: { writeText },
    });
    const downloadClick = vi
      .spyOn(HTMLAnchorElement.prototype, "click")
      .mockImplementation(function (this: HTMLAnchorElement) {
        expect(this.href).toBe("https://storage.test/signed-original");
        expect(this.download).toBe("family.jpg");
      });
    renderPage();
    await screen.findByRole("img", { name: "Family picnic" });

    await user.click(screen.getByRole("button", { name: "Photo options" }));
    await user.click(
      screen.getByRole("menuitem", { name: "Download original" }),
    );
    await waitFor(() => {
      expect(getOriginalMediaDelivery).toHaveBeenCalledWith(
        "oliver-family",
        photo.media_upload.id,
      );
      expect(downloadClick).toHaveBeenCalledOnce();
    });
    expect(await screen.findByRole("status")).toHaveTextContent(
      "Original download started",
    );

    await user.click(screen.getByRole("button", { name: "Photo options" }));
    await user.click(
      screen.getByRole("menuitem", { name: "Copy Fambam link" }),
    );
    await waitFor(() => {
      expect(writeText).toHaveBeenCalledWith(
        `${window.location.origin}/families/oliver-family/photos/${photo.id}`,
      );
    });
    expect(screen.getByRole("status")).toHaveTextContent("Fambam link copied");

    await user.click(screen.getByRole("button", { name: "Photo options" }));
    await user.click(
      screen.getByRole("menuitem", { name: "Add to collection…" }),
    );
    expect(
      await screen.findByRole("dialog", { name: "Add to collection" }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Cancel" }));

    await user.click(screen.getByRole("button", { name: "Photo options" }));
    await user.click(screen.getByRole("menuitem", { name: "Edit photo" }));
    expect(
      await screen.findByRole("dialog", { name: "Edit “Family picnic”" }),
    ).toBeInTheDocument();
  });

  it("renders the frozen empty and error states accessibly", async () => {
    vi.mocked(getPhotos).mockResolvedValue({ items: [], next_cursor: null });
    const first = renderPage();
    expect(
      await screen.findByRole("heading", { name: "No photographs yet" }),
    ).toBeInTheDocument();
    first.unmount();
    vi.mocked(getPhotos).mockRejectedValue(new Error("offline"));
    renderPage();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The photograph archive could not be loaded.",
    );
  });
});
