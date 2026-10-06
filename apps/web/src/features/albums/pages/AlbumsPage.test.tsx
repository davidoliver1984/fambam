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
  createCollection,
  getCollections,
  populateCollection,
} from "@/features/collections/api/collectionApi";
import { getFamilySpace } from "@/features/family-spaces/api/familySpaceApi";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPeople } from "@/features/people/api/personApi";
import { getPhotoVersions } from "@/features/photos/api/photoEditorApi";
import { getPhotos } from "@/features/photos/api/photoApi";
import {
  addPhotoToAlbum,
  deleteAlbum,
  getAlbums,
  requestAlbumExport,
  updateAlbum,
  uploadPhotoToAlbum,
} from "../api/albumApi";
import type { Album } from "../types/album";
import { AlbumsPage } from "./AlbumsPage";

vi.mock("../api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  createAlbum: vi.fn(),
  deleteAlbum: vi.fn(),
  getAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
  requestAlbumExport: vi.fn(),
  setAlbumCover: vi.fn(),
  updateAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));
vi.mock("@/features/collections/api/collectionApi", () => ({
  addCollectionPhoto: vi.fn(),
  createCollection: vi.fn(),
  deleteCollection: vi.fn(),
  getCollection: vi.fn(),
  getCollections: vi.fn(),
  populateCollection: vi.fn(),
  removeCollectionPhoto: vi.fn(),
  requestCollectionExport: vi.fn(),
}));
vi.mock("@/features/family-spaces/api/familySpaceApi", () => ({
  getFamilySpace: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
}));
vi.mock("@/features/people/api/personApi", () => ({
  getPeople: vi.fn(),
}));
vi.mock("@/features/photos/api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
}));
vi.mock("@/features/photos/api/photoApi", () => ({ getPhotos: vi.fn() }));

const album: Album = {
  id: "01K80000000000000000000000",
  name: "Blackpool, 1986",
  description: "Family holiday by the sea",
  starts_on: "1986-08-10",
  ends_on: "1986-08-17",
  location: "Blackpool",
  tags: [{ id: "tag-1", label: "Seaside" }],
  people: [{ id: "person-1", name: "William Mercer" }],
  cover: {
    photo_id: "photo-cover",
    media_upload_id: "upload-cover",
    focal_x: 0.25,
    focal_y: 0.7,
  },
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "Sarah Mercer" },
  created_at: "2026-09-01T10:00:00+00:00",
  updated_at: "2026-09-02T10:00:00+00:00",
  is_new: true,
  photo_count: 24,
  event_id: "event-1",
  event: {
    id: "event-1",
    name: "Blackpool summer holiday",
    starts_on: "1986-08-10",
  },
  guest_participation: "none",
  photos: [],
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

const christmas: Album = {
  ...album,
  id: "01K80000000000000000000001",
  name: "Christmas at Nan’s",
  starts_on: "1994-12-24",
  ends_on: null,
  location: "Ashton-under-Lyne",
  tags: [{ id: "tag-2", label: "Christmas" }],
  people: [{ id: "person-2", name: "Margaret Shaw" }],
  cover: null,
  creator: null,
  created_at: "2026-08-01T10:00:00+00:00",
  updated_at: "2026-09-20T10:00:00+00:00",
  is_new: false,
  photo_count: 18,
  event: null,
  event_id: null,
};

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      { path: "/families/:familySlug/albums", element: <AlbumsPage /> },
      {
        path: "/families/:familySlug/albums/:albumId",
        element: <p>Album detail</p>,
      },
    ],
    { initialEntries: ["/families/family-archive/albums"] },
  );
  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getAlbums).mockImplementation((_familySlug, criteria) => {
    const requested = criteria ?? {};
    let items = [album, christmas];
    const term = requested.q?.toLocaleLowerCase();
    if (term) {
      items = items.filter((item) =>
        [
          item.name,
          item.description ?? "",
          item.location ?? "",
          item.creator?.name ?? "",
          item.event?.name ?? "",
          ...(item.people ?? []).map((person) => person.name),
          ...(item.tags ?? []).map((tag) => tag.label),
        ]
          .join(" ")
          .toLocaleLowerCase()
          .includes(term),
      );
    }
    if (requested.person_ids)
      items = items.filter((item) =>
        item.people?.some((person) =>
          requested.person_ids?.includes(person.id),
        ),
      );
    if (requested.sort === "oldest")
      items = items.toSorted((left, right) =>
        (left.starts_on ?? "").localeCompare(right.starts_on ?? ""),
      );
    else if (requested.sort === "updated")
      items = items.toSorted((left, right) =>
        right.updated_at.localeCompare(left.updated_at),
      );
    else
      items = items.toSorted((left, right) =>
        (right.starts_on ?? "").localeCompare(left.starts_on ?? ""),
      );
    return Promise.resolve({ items, next_cursor: null });
  });
  vi.mocked(getFamilySpace).mockResolvedValue({
    id: "family-1",
    slug: "family-archive",
    name: "Family Archive",
    description: null,
    default_visibility: "family_space",
    status: "active",
    role: "owner",
    permissions: {
      can_update_family_settings: true,
      can_manage_members: true,
      can_manage_invitations: true,
      can_transfer_ownership: true,
      can_leave_family: false,
    },
  });
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: false,
    versions: [],
  });
  vi.mocked(getMediaVariantDelivery).mockResolvedValue({
    asset: "variant",
    transform_name: "card",
    processing_version: 1,
    url: "https://storage.test/cover",
    method: "GET",
    expires_at: "2026-09-26T12:00:00+00:00",
  });
  vi.mocked(getPhotos).mockResolvedValue({ items: [], next_cursor: null });
  vi.mocked(getPeople).mockResolvedValue([]);
  vi.mocked(getCollections).mockResolvedValue([
    {
      id: "collection-1",
      name: "Prints for Mum",
      description: null,
      purpose: "prints",
      created_at: null,
      updated_at: null,
      photo_count: 0,
      preview_photo: null,
    },
  ]);
  vi.mocked(updateAlbum).mockResolvedValue(album);
  vi.mocked(deleteAlbum).mockResolvedValue();
  vi.mocked(requestAlbumExport).mockResolvedValue({ id: "export-1" });
  vi.mocked(addPhotoToAlbum).mockResolvedValue();
  vi.mocked(uploadPhotoToAlbum).mockResolvedValue({} as never);
  vi.mocked(createCollection).mockResolvedValue({
    id: "collection-2",
    name: "New collection",
    description: null,
    purpose: null,
    created_at: null,
    updated_at: null,
    photo_count: 0,
    preview_photo: null,
  });
  vi.mocked(populateCollection).mockResolvedValue({
    id: "collection-1",
    name: "Prints for Mum",
    description: null,
    purpose: "prints",
    created_at: null,
    updated_at: null,
    photo_count: 0,
    preview_photo: null,
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

async function openMenu(name = album.name) {
  const user = userEvent.setup();
  await screen.findByText(name);
  await user.click(
    screen.getByRole("button", { name: `Album options for ${name}` }),
  );
  return user;
}

describe("AlbumsPage", () => {
  it("loads the next server page automatically and merges a month across the page boundary", async () => {
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
    const second = {
      ...album,
      id: "01K80000000000000000000002",
      name: "Blackpool revisited",
      starts_on: "1986-08-20",
    };
    vi.mocked(getAlbums).mockImplementation((_familySlug, _criteria, cursor) =>
      Promise.resolve(
        cursor === null
          ? { items: [album], next_cursor: "opaque-next-page" }
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

    expect(await screen.findByText(second.name)).toBeInTheDocument();
    expect(getAlbums).toHaveBeenCalledTimes(2);
    expect(vi.mocked(getAlbums).mock.calls[1]?.[2]).toBe("opaque-next-page");
    expect(
      screen.getAllByRole("heading", { name: "August 1986" }),
    ).toHaveLength(1);
  });

  it("keeps loaded Albums visible during continuation and exposes the manual fallback only without observation", async () => {
    vi.stubGlobal("IntersectionObserver", undefined);
    const second = {
      ...christmas,
      id: "01K80000000000000000000003",
      name: "Fallback page",
    };
    let resolveNextPage:
      ((page: { items: Album[]; next_cursor: null }) => void) | undefined;
    vi.mocked(getAlbums).mockImplementation((_familySlug, _criteria, cursor) =>
      cursor === null
        ? Promise.resolve({ items: [album], next_cursor: "next-page" })
        : new Promise((resolve) => {
            resolveNextPage = resolve;
          }),
    );
    const user = userEvent.setup();

    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Load more albums" }),
    );
    expect(await screen.findByText("Loading more albums…")).toHaveAttribute(
      "role",
      "status",
    );
    expect(screen.getByText(album.name)).toBeInTheDocument();
    resolveNextPage?.({ items: [second], next_cursor: null });

    expect(await screen.findByText(second.name)).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Load more albums" }),
    ).not.toBeInTheDocument();
  });

  it("renders New only from the backend-authoritative flag", async () => {
    renderPage();
    const card = (await screen.findByText(album.name)).closest("article");
    const olderCard = screen.getByText(christmas.name).closest("article");
    expect(within(card as HTMLElement).getByText("New")).toBeInTheDocument();
    expect(
      within(olderCard as HTMLElement).queryByText("New"),
    ).not.toBeInTheDocument();
  });

  it("shows the canonical Home to Albums breadcrumb", async () => {
    renderPage();
    const breadcrumb = await screen.findByRole("navigation", {
      name: "Breadcrumb",
    });
    expect(
      within(breadcrumb).getByRole("link", { name: "Home" }),
    ).toHaveAttribute("href", "/families/family-archive");
    expect(within(breadcrumb).getByText("Albums")).toHaveAttribute(
      "aria-current",
      "page",
    );
  });

  it("uses the accepted cover and focal position without substituting the first Photo", async () => {
    renderPage();
    await screen.findByText(album.name);
    await waitFor(() => {
      expect(document.querySelector("img")).not.toBeNull();
    });
    const image = document.querySelector("img");
    expect(image).toHaveAttribute("src", "https://storage.test/cover");
    expect(image).toHaveStyle({ objectPosition: "25% 70%" });
    expect(screen.getByLabelText("No cover photo")).toBeInTheDocument();
    expect(screen.getByText("24 photos · Sarah Mercer")).toBeInTheDocument();
  });

  it("groups by Album date and supports all three sort modes", async () => {
    const user = userEvent.setup();
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "December 1994" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "August 1986" }),
    ).toBeInTheDocument();
    const titles = () =>
      screen
        .getAllByRole("heading", { level: 3 })
        .map((item) => item.textContent);
    expect(titles()).toEqual(["Christmas at Nan’s", "Blackpool, 1986"]);
    await user.selectOptions(screen.getByLabelText("Sort"), "oldest");
    await waitFor(() => {
      expect(titles()).toEqual(["Blackpool, 1986", "Christmas at Nan’s"]);
    });
    await user.selectOptions(screen.getByLabelText("Sort"), "updated");
    await waitFor(() => {
      expect(titles()).toEqual(["Christmas at Nan’s", "Blackpool, 1986"]);
    });
    expect(vi.mocked(getAlbums).mock.calls.at(-1)?.[1]).toEqual({
      sort: "updated",
    });
  });

  it("keeps Albums without a real Album date in an undated group", async () => {
    vi.mocked(getAlbums).mockResolvedValue({
      items: [
        album,
        {
          ...christmas,
          starts_on: null,
          created_at: "2035-01-01T10:00:00+00:00",
        },
      ],
      next_cursor: null,
    });
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "Undated albums" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "January 2035" }),
    ).not.toBeInTheDocument();
  });

  it("searches real metadata, creates filter chips, and clears filtered-empty state", async () => {
    const user = userEvent.setup();
    renderPage();
    const search = await screen.findByPlaceholderText("Search albums…");
    await user.type(search, "William");
    await user.click(screen.getByRole("button", { name: /William Mercer/ }));
    await waitFor(() => {
      expect(vi.mocked(getAlbums).mock.calls.at(-1)?.[1]).toEqual({
        sort: "newest",
        person_ids: ["person-1"],
      });
    });
    expect(
      screen.getByRole("button", { name: /William Mercer/ }),
    ).toBeInTheDocument();
    expect(screen.getByText("Blackpool, 1986")).toBeInTheDocument();
    expect(screen.queryByText("Christmas at Nan’s")).not.toBeInTheDocument();
    await user.type(search, "does not exist");
    expect(
      screen.getByRole("heading", { name: "No matches found" }),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Clear search and filters" }),
    );
    expect(await screen.findByText("Christmas at Nan’s")).toBeInTheDocument();
  });

  it("supports free-text search without choosing a suggestion", async () => {
    const user = userEvent.setup();
    renderPage();
    const search = await screen.findByPlaceholderText("Search albums…");
    await user.type(search, "Sarah Mercer{Enter}");
    await waitFor(() => {
      expect(screen.getByText("Blackpool, 1986")).toBeInTheDocument();
      expect(screen.queryByText("Christmas at Nan’s")).not.toBeInTheDocument();
    });
    expect(vi.mocked(getAlbums).mock.calls.at(-1)?.[1]).toEqual({
      sort: "newest",
      q: "Sarah Mercer",
    });
    expect(screen.queryByRole("listbox")).not.toBeInTheDocument();
  });

  it("makes the card surface a single keyboard link while keeping its menu interactive", async () => {
    renderPage();
    await screen.findByText(album.name);
    const card = screen.getByText(album.name).closest("article");
    expect(card).not.toBeNull();
    expect(
      within(card as HTMLElement).getByRole("link", { name: album.name }),
    ).toHaveClass("ui-archive-card__stretched-link");
    expect(
      within(card as HTMLElement).getByRole("button", {
        name: `Album options for ${album.name}`,
      }),
    ).toBeEnabled();
    const links = within(card as HTMLElement).getAllByRole("link");
    expect(links).toHaveLength(2);
    expect(links[0]).toHaveAttribute("tabindex", "-1");
  });

  it("switches between the approved grid and list views", async () => {
    const user = userEvent.setup();
    const { container } = renderPage();
    await screen.findByText(album.name);
    expect(
      container.querySelector(".album-card-grid--grid"),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "List view" }));
    expect(
      container.querySelector(".album-card-grid--list"),
    ).toBeInTheDocument();
  });

  it("preserves the exact menu order and keyboard navigation", async () => {
    renderPage();
    const user = await openMenu();
    const menu = screen.getByRole("menu");
    expect(
      within(menu)
        .getAllByRole("menuitem")
        .map((item) => item.textContent.trim()),
    ).toEqual([
      "Open album",
      "Edit album",
      "Add photos",
      "Add photos to collection…",
      "Copy Fambam link",
      "Download / Export album",
      "Manage people",
      "Manage tags",
      "Delete album",
    ]);
    expect(
      within(menu).getByRole("menuitem", { name: "Open album" }),
    ).toHaveFocus();
    expect(
      within(menu).getByRole("menuitem", { name: "Add photos" }),
    ).toHaveAttribute(
      "href",
      `/families/family-archive/albums/${album.id}/uploads`,
    );
    await user.keyboard("{ArrowDown}");
    expect(
      within(menu).getByRole("menuitem", { name: "Edit album" }),
    ).toHaveFocus();
  });

  it("updates Album details, people, and tags through typed production mutations", async () => {
    renderPage();
    let user = await openMenu();
    await user.click(screen.getByRole("menuitem", { name: "Edit album" }));
    await user.clear(screen.getByLabelText("Name"));
    await user.type(screen.getByLabelText("Name"), "Blackpool holiday");
    await user.click(screen.getByRole("button", { name: "Save changes" }));
    await waitFor(() => {
      expect(updateAlbum).toHaveBeenCalledWith(
        "family-archive",
        album.id,
        expect.objectContaining({ name: "Blackpool holiday" }),
      );
    });

    vi.mocked(getPeople).mockResolvedValue([
      { id: "person-1", preferred_name: "William Mercer" },
      { id: "person-2", preferred_name: "Margaret Shaw" },
    ] as never);
    user = await openMenu();
    await user.click(screen.getByRole("menuitem", { name: "Manage people" }));
    await user.click(
      await screen.findByRole("checkbox", { name: "Margaret Shaw" }),
    );
    await user.click(screen.getByRole("button", { name: "Save changes" }));
    await waitFor(() => {
      expect(updateAlbum).toHaveBeenCalledWith("family-archive", album.id, {
        person_ids: ["person-1", "person-2"],
      });
    });

    user = await openMenu();
    await user.click(screen.getByRole("menuitem", { name: "Manage tags" }));
    await user.clear(screen.getByLabelText("Tags"));
    await user.type(screen.getByLabelText("Tags"), "Seaside, Holiday");
    await user.click(screen.getByRole("button", { name: "Save changes" }));
    await waitFor(() => {
      expect(updateAlbum).toHaveBeenCalledWith("family-archive", album.id, {
        tags: ["Seaside", "Holiday"],
      });
    });
  });

  it("matches the collection picker structure and uses real collections", async () => {
    renderPage();
    const user = await openMenu();
    await user.click(
      screen.getByRole("menuitem", { name: "Add photos to collection…" }),
    );
    expect(
      screen.getByText("A snapshot of the current source Photos"),
    ).toBeInTheDocument();
    expect(screen.getByText("Private to you")).toBeInTheDocument();
    expect(
      await screen.findByRole("option", { name: "Prints for Mum" }),
    ).toBeInTheDocument();
  });

  it("confirms deletion and reports both success and failure", async () => {
    renderPage();
    let user = await openMenu();
    await user.click(screen.getByRole("menuitem", { name: "Delete album" }));
    expect(
      screen.getByRole("heading", { name: `Delete “${album.name}”?` }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/Photos remain safely in the Family Space/),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Delete album" }));
    await waitFor(() => {
      expect(deleteAlbum).toHaveBeenCalledWith("family-archive", album.id);
    });

    vi.mocked(deleteAlbum).mockRejectedValueOnce(new Error("no"));
    user = await openMenu(christmas.name);
    await user.click(screen.getByRole("menuitem", { name: "Delete album" }));
    await user.click(screen.getByRole("button", { name: "Delete album" }));
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The Album could not be deleted.",
    );
  });

  it("shows distinct no-Albums and filtered-empty states without restoring inline creation", async () => {
    vi.mocked(getAlbums).mockResolvedValue({ items: [], next_cursor: null });
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "No albums yet" }),
    ).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: "Create album" })).toHaveLength(
      2,
    );
    expect(
      screen.getAllByRole("link", { name: "Create album" })[0],
    ).toHaveAttribute("href", "/families/family-archive/albums/new");
    expect(
      screen.queryByRole("heading", { name: "Create an Album" }),
    ).not.toBeInTheDocument();
  });
});
