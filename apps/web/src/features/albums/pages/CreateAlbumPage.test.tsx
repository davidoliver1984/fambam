import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getFamilySpace } from "@/features/family-spaces/api/familySpaceApi";
import { getPeople } from "@/features/people/api/personApi";
import { getPhotos } from "@/features/photos/api/photoApi";
import type { Person } from "@/features/people/types/person";
import type { Photo } from "@/features/photos/types/photo";

import { albumKeys } from "../api/albumKeys";
import {
  createAlbum,
  setAlbumCover,
  uploadPhotoToAlbum,
} from "../api/albumApi";
import type { Album } from "../types/album";
import { CreateAlbumPage } from "./CreateAlbumPage";

const NativeURL = URL;

vi.mock("../api/albumApi", () => ({
  createAlbum: vi.fn(),
  setAlbumCover: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));
vi.mock("@/features/family-spaces/api/familySpaceApi", () => ({
  getFamilySpace: vi.fn(),
}));
vi.mock("@/features/people/api/personApi", () => ({ getPeople: vi.fn() }));
vi.mock("@/features/photos/api/photoApi", () => ({ getPhotos: vi.fn() }));
vi.mock("@/features/photos/components/PhotoPresentationImage", () => ({
  PhotoPresentationImage: ({ alt = "" }: { alt?: string }) => (
    <img alt={alt} src="https://example.test/photo.jpg" />
  ),
}));

const createdAlbum: Album = {
  id: "01KALBUM000000000000000000",
  name: "Blackpool, 1986",
  description: "A discovered envelope.",
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "David Oliver" },
  created_at: "2026-09-26T10:00:00Z",
  updated_at: "2026-09-26T10:00:00Z",
  photo_count: 0,
  event_id: null,
  event: null,
  guest_participation: "none",
  photos: [],
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

const person = {
  id: "01KPERSON00000000000000000",
  preferred_name: "William Mercer",
} as Person;

const photo = {
  id: "01KPHOTO000000000000000000",
  caption: "At the pier",
  media_upload: {
    id: "01KUPLOAD00000000000000000",
    client_filename: "pier.jpg",
  },
} as Photo;

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug/albums/new",
        element: <CreateAlbumPage />,
      },
      {
        path: "/families/:familySlug/albums/:albumId",
        element: <p>Created album destination</p>,
      },
    ],
    { initialEntries: ["/families/mercer-family/albums/new"] },
  );
  const result = render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...result, client };
}

beforeEach(() => {
  class MockURL extends NativeURL {
    static createObjectURL = vi.fn(() => "blob:cover-preview");
    static revokeObjectURL = vi.fn();
  }
  vi.stubGlobal("URL", MockURL);
  vi.mocked(getFamilySpace).mockResolvedValue({
    id: "01KFAMILY0000000000000000",
    slug: "mercer-family",
    name: "Mercer family",
    status: "active",
    role: "owner",
    current_user_person_id: null,
  });
  vi.mocked(getPeople).mockResolvedValue([person]);
  vi.mocked(getPhotos).mockResolvedValue([photo]);
  vi.mocked(createAlbum).mockResolvedValue(createdAlbum);
  vi.mocked(setAlbumCover).mockResolvedValue(createdAlbum);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  vi.unstubAllGlobals();
});

describe("CreateAlbumPage", () => {
  it("preserves the approved three-step flow and submits real Album metadata", async () => {
    const user = userEvent.setup();
    const { client } = renderPage();
    client.setQueryData(albumKeys.list("mercer-family"), []);

    await screen.findByRole("heading", { name: "Create a new album" });
    await user.click(screen.getByRole("button", { name: "Go to Review" }));
    expect(screen.getByText("Enter an album title.")).toBeInTheDocument();

    await user.type(screen.getByLabelText("Album title"), "Blackpool, 1986");
    await user.type(
      screen.getByLabelText("What’s the story?"),
      "A discovered envelope with @William Mercer.",
    );
    await user.type(screen.getByLabelText("When was it?"), "1986-08-12");
    await user.type(screen.getByLabelText("Where?"), "Blackpool, Lancashire");
    expect(screen.getByPlaceholderText("Add tag & press enter")).toBeVisible();
    await user.type(screen.getByLabelText("Album tags"), "holiday{Enter}");
    await user.click(
      screen.getByRole("button", { name: "Go to People & access" }),
    );

    expect(
      await screen.findByText("Who belongs in this album?"),
    ).toBeInTheDocument();
    await waitFor(() => {
      expect(
        screen.getByRole("heading", { name: "Who belongs in this album?" }),
      ).toHaveFocus();
    });
    await user.click(screen.getByLabelText("Include William Mercer"));
    await user.click(screen.getByRole("button", { name: /Continue/ }));

    expect(
      screen.getByText(
        "Almost there — review the details below before creating your album.",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("No cover photo yet")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: /Create album/ }));

    await waitFor(() => {
      expect(createAlbum).toHaveBeenCalledWith("mercer-family", {
        name: "Blackpool, 1986",
        description: {
          schema_version: 1,
          blocks: [
            {
              type: "paragraph",
              content: [
                { type: "text", text: "A discovered envelope with " },
                {
                  type: "mention",
                  person_id: person.id,
                  label: "William Mercer",
                },
                { type: "text", text: "." },
              ],
            },
          ],
        },
        visibility: "family_space",
        starts_on: "1986-08-12",
        ends_on: null,
        location: "Blackpool, Lancashire",
        tags: ["holiday"],
        person_ids: [person.id],
      });
    });
    expect(
      await screen.findByText("Created album destination"),
    ).toBeInTheDocument();
    expect(
      client.getQueryState(albumKeys.list("mercer-family"))?.isInvalidated,
    ).toBe(true);
    expect(
      client.getQueryData(albumKeys.detail("mercer-family", createdAlbum.id)),
    ).toEqual(createdAlbum);
  });

  it("uses an authorized existing Photo as the cover without uploading a duplicate", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByLabelText("Album title");
    await user.type(screen.getByLabelText("Album title"), "Pier days");
    await user.click(screen.getByRole("button", { name: /Continue/ }));
    await screen.findByText("Who belongs in this album?");
    await user.click(screen.getByRole("button", { name: /Continue/ }));
    await user.click(screen.getByRole("button", { name: "Add cover" }));
    await user.click(
      screen.getByRole("button", { name: "Use At the pier as cover" }),
    );
    await user.click(screen.getByRole("button", { name: /Create album/ }));

    await waitFor(() => {
      expect(setAlbumCover).toHaveBeenCalledWith(
        "mercer-family",
        createdAlbum.id,
        {
          photoId: photo.id,
          confirmVisibilityWidening: true,
          focalX: 0.5,
          focalY: 0.5,
        },
      );
    });
    expect(uploadPhotoToAlbum).not.toHaveBeenCalled();
  });

  it("keeps a created Album safe and retries only a failed asynchronous cover upload", async () => {
    const user = userEvent.setup();
    vi.mocked(uploadPhotoToAlbum)
      .mockRejectedValueOnce(new Error("Storage unavailable"))
      .mockResolvedValueOnce({
        id: "01KCOVERUPLOAD0000000000000",
        state: "processing",
        target_album_id: createdAlbum.id,
        cover_intent_accepted: true,
      } as never);
    renderPage();
    await screen.findByLabelText("Album title");
    await user.type(screen.getByLabelText("Album title"), "Uploaded cover");
    await user.click(screen.getByRole("button", { name: /Continue/ }));
    await screen.findByText("Who belongs in this album?");
    await user.click(screen.getByRole("button", { name: /Continue/ }));
    await user.click(screen.getByRole("button", { name: "Add cover" }));
    await user.upload(
      screen.getByLabelText("Upload new cover"),
      new File(["cover"], "cover.jpg", { type: "image/jpeg" }),
    );
    await user.click(screen.getByRole("button", { name: "Reposition" }));
    await user.click(screen.getByRole("button", { name: /Create album/ }));

    expect(
      await screen.findByText(
        "The album was created, but its cover could not be finished. Your album is safe; try the cover again.",
      ),
    ).toBeInTheDocument();
    expect(createAlbum).toHaveBeenCalledTimes(1);
    expect(uploadPhotoToAlbum).toHaveBeenCalledWith(
      "mercer-family",
      createdAlbum.id,
      expect.objectContaining({ name: "cover.jpg" }),
      true,
      0.3,
    );

    await user.click(
      screen.getByRole("button", { name: /Retry cover upload/ }),
    );
    expect(
      await screen.findByText("Created album destination"),
    ).toBeInTheDocument();
    expect(createAlbum).toHaveBeenCalledTimes(1);
    expect(uploadPhotoToAlbum).toHaveBeenCalledTimes(2);
  });
});
