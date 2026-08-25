import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getFamilySpace } from "@/features/family-spaces/api/familySpaceApi";
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

const album: Album = {
  id: "01K80000000000000000000000",
  name: "Selected memories",
  description: null,
  visibility: "selected",
  created_by: 1,
  event_id: "01KB0000000000000000000000",
  event: {
    id: "01KB0000000000000000000000",
    name: "Family wedding",
    starts_on: "2026-08-25",
  },
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
  vi.mocked(getAlbums).mockResolvedValue([album]);
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
  it("shows scoped Event Album contribution controls without offering Contributor album creation", async () => {
    renderPage();
    expect(await screen.findByText("Selected memories")).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "Create an Album" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByLabelText("Upload a new Photo to this Album"),
    ).toBeInTheDocument();
    expect(screen.getByText(/may widen who can see it/i)).toBeInTheDocument();
  });

  it("passes explicit widening confirmation when adding an existing Photo", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Selected memories");
    await user.type(
      screen.getByLabelText("Photo ID"),
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
