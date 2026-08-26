import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  createPhoto,
  getDeletedPhotos,
  getPhotos,
  restorePhoto,
} from "../api/photoApi";
import { getDuplicateHolds } from "../api/photoDuplicateApi";
import type { Photo } from "../types/photo";
import { PhotosPage } from "./PhotosPage";

vi.mock("../api/photoApi", () => ({
  createPhoto: vi.fn(),
  deletePhoto: vi.fn(),
  getDeletedPhotos: vi.fn(),
  getPhotos: vi.fn(),
  restorePhoto: vi.fn(),
}));
vi.mock("../api/photoDuplicateApi", () => ({
  getDuplicateHolds: vi.fn(),
  resolveDuplicateHold: vi.fn(),
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
  description: null,
  archive_source_description: null,
  historical_date: null,
  location_description: null,
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  people: [],
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

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/photos", element: <PhotosPage /> }],
    { initialEntries: ["/families/oliver-family/photos"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotos).mockResolvedValue([photo]);
  vi.mocked(getDeletedPhotos).mockResolvedValue([]);
  vi.mocked(createPhoto).mockResolvedValue({
    outcome: "photo_created",
    photo,
  });
  vi.mocked(getDuplicateHolds).mockResolvedValue([]);
  vi.mocked(restorePhoto).mockResolvedValue(photo);
});

afterEach(() => {
  cleanup();
  vi.mocked(getPhotos).mockReset();
  vi.mocked(getDeletedPhotos).mockReset();
  vi.mocked(createPhoto).mockReset();
  vi.mocked(getDuplicateHolds).mockReset();
  vi.mocked(restorePhoto).mockReset();
});

describe("PhotosPage", () => {
  it("shows restorable tombstones without presenting them in the active archive", async () => {
    vi.mocked(getDeletedPhotos).mockResolvedValue([
      {
        id: photo.id,
        caption: "Removed picnic",
        client_filename: "family.jpg",
        deleted_at: "2026-08-24T12:00:00Z",
        permissions: { can_restore: true },
      },
    ]);
    const user = userEvent.setup();
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "Recently removed Photos" }),
    ).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Restore" }));
    expect(restorePhoto).toHaveBeenCalledWith("oliver-family", photo.id);
  });
  it("renders Photo visibility and tags from the query", async () => {
    renderPage();
    expect(await screen.findByText("Family picnic")).toBeInTheDocument();
    expect(screen.getAllByText("Private")).toHaveLength(2);
    expect(screen.getByText("Picnic")).toBeInTheDocument();
    expect(getPhotos).toHaveBeenCalledWith(
      "oliver-family",
      {
        historical_year: "",
        location: "",
        tag: "",
        without_confirmed_date: false,
      },
      expect.any(AbortSignal),
    );
  });

  it("creates through the feature mutation and invalidates the list", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Family picnic");
    await user.type(
      screen.getByLabelText("Ready MediaUpload ID"),
      photo.media_upload.id,
    );
    await user.type(screen.getByLabelText("Caption"), "Nan at the seaside");
    await user.type(screen.getByLabelText("Tags"), "Holiday, Seaside");
    await user.click(screen.getByRole("button", { name: "Create Photo" }));

    await waitFor(() => {
      expect(createPhoto).toHaveBeenCalledWith(
        "oliver-family",
        expect.objectContaining({
          media_upload_id: photo.media_upload.id,
          caption: "Nan at the seaside",
          tags: ["Holiday", "Seaside"],
        }),
      );
      expect(getPhotos).toHaveBeenCalledTimes(2);
    });
    expect(screen.getByRole("status")).toHaveTextContent(
      "Photo record created.",
    );
  });

  it("requires an explicit choice when exact duplicate candidates are returned", async () => {
    vi.mocked(createPhoto)
      .mockResolvedValueOnce({
        outcome: "duplicate_detected",
        candidates: [
          {
            id: photo.id,
            caption: photo.caption,
            visibility: photo.visibility,
            client_filename: photo.media_upload.client_filename,
            created_at: photo.created_at,
          },
        ],
      })
      .mockResolvedValueOnce({ outcome: "existing_photo", photo });
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Family picnic");
    await user.type(
      screen.getByLabelText("Ready MediaUpload ID"),
      photo.media_upload.id,
    );
    await user.click(screen.getByRole("button", { name: "Create Photo" }));
    expect(
      await screen.findByRole("group", {
        name: "Matching Photos already in the archive",
      }),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Use existing Photo" }),
    );
    await waitFor(() => {
      expect(createPhoto).toHaveBeenLastCalledWith(
        "oliver-family",
        expect.objectContaining({
          duplicate_resolution: "use_existing",
          existing_photo_id: photo.id,
        }),
      );
    });
  });

  it("renders empty and error states accessibly", async () => {
    vi.mocked(getPhotos).mockResolvedValue([]);
    const first = renderPage();
    expect(
      await screen.findByText("No Photo records have been created yet."),
    ).toBeInTheDocument();
    first.unmount();
    vi.mocked(getPhotos).mockRejectedValue(new Error("offline"));
    renderPage();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "archive could not be loaded",
    );
  });
});
