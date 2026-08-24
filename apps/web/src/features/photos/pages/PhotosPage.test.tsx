import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { createPhoto, getPhotos } from "../api/photoApi";
import type { Photo } from "../types/photo";
import { PhotosPage } from "./PhotosPage";

vi.mock("../api/photoApi", () => ({
  createPhoto: vi.fn(),
  getPhotos: vi.fn(),
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
  provenance: {
    photographer: { person: null, description: null },
    scanner: { person: null, description: null },
    physical_owner: { person: null, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
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
  vi.mocked(createPhoto).mockResolvedValue(photo);
});

afterEach(() => {
  cleanup();
  vi.mocked(getPhotos).mockReset();
  vi.mocked(createPhoto).mockReset();
});

describe("PhotosPage", () => {
  it("renders Photo visibility and tags from the query", async () => {
    renderPage();
    expect(await screen.findByText("Family picnic")).toBeInTheDocument();
    expect(screen.getAllByText("Private")).toHaveLength(2);
    expect(screen.getByText("Picnic")).toBeInTheDocument();
    expect(getPhotos).toHaveBeenCalledWith(
      "oliver-family",
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
