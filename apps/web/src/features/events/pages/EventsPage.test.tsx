import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getFamilySpace } from "@/features/family-spaces/api/familySpaceApi";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPhotoVersions } from "@/features/photos/api/photoEditorApi";

import { getDeletedEvents, getEvents } from "../api/eventApi";
import { EventsPage } from "./EventsPage";

vi.mock("../api/eventApi", () => ({
  createEvent: vi.fn(),
  getDeletedEvents: vi.fn(),
  getEvents: vi.fn(),
  restoreEvent: vi.fn(),
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

function renderPage() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/events", element: <EventsPage /> }],
    { initialEntries: ["/families/family-archive/events"] },
  );
  return render(
    <QueryClientProvider client={client}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getFamilySpace).mockResolvedValue({
    id: "family-1",
    slug: "family-archive",
    name: "Family Archive",
    status: "active",
    role: "administrator",
  });
  vi.mocked(getDeletedEvents).mockResolvedValue([]);
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: false,
    versions: [],
  });
  vi.mocked(getMediaVariantDelivery).mockResolvedValue({
    asset: "variant",
    transform_name: "thumbnail",
    processing_version: 1,
    url: "https://storage.test/event-thumbnail",
    method: "GET",
    expires_at: "2026-09-19T20:00:00Z",
  });
  vi.mocked(getEvents).mockResolvedValue([
    {
      id: "event-1",
      name: "Christmas at Nan's",
      description: "Family dinner",
      starts_on: "1994-12-25",
      ends_on: "1994-12-25",
      location: "Ashton-under-Lyne",
      status: "completed",
      created_by: 1,
      creator: { id: 1, name: "David" },
      permissions: {
        can_update: true,
        can_manage_admissions: true,
        can_review_duplicates: true,
        can_manage_exports: true,
        can_delete: true,
        can_restore: false,
        can_create_album: true,
      },
      presentation: {
        preview: { photo_id: "photo-1", media_upload_id: "upload-1" },
        photo_count: 18,
        album_count: 1,
        story_count: 2,
        people_count: 6,
      },
    },
    {
      id: "event-2",
      name: "Summer holiday",
      description: null,
      starts_on: "1986-08-12",
      ends_on: "1986-08-19",
      location: "Blackpool",
      status: "completed",
      created_by: 1,
      creator: { id: 1, name: "David" },
      permissions: {
        can_update: true,
        can_manage_admissions: true,
        can_review_duplicates: true,
        can_manage_exports: true,
        can_delete: true,
        can_restore: false,
        can_create_album: true,
      },
      presentation: {
        preview: null,
        photo_count: 0,
        album_count: 0,
        story_count: 0,
        people_count: 0,
      },
    },
  ]);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("EventsPage", () => {
  it("renders authorised Event imagery and truthful summaries", async () => {
    renderPage();

    await waitFor(() => {
      expect(document.querySelector("img")).toHaveAttribute(
        "src",
        "https://storage.test/event-thumbnail",
      );
    });
    expect(
      screen.getByText("Ashton-under-Lyne · 18 photographs · 2 stories"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Christmas at Nan's" }),
    ).toHaveAttribute("data-entity-kind", "event");
  });

  it("filters and switches presentation without refetching server state", async () => {
    const user = userEvent.setup();
    renderPage();

    await screen.findByText("Christmas at Nan's");
    await user.type(
      screen.getByRole("searchbox", { name: "Search" }),
      "summer",
    );
    expect(screen.queryByText("Christmas at Nan's")).not.toBeInTheDocument();
    expect(screen.getByText("Summer holiday")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "List view" }));
    expect(screen.getByRole("button", { name: "List view" })).toHaveAttribute(
      "aria-pressed",
      "true",
    );
    expect(getEvents).toHaveBeenCalledTimes(1);
  });
});
