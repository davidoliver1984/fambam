import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  getEvent,
  getDuplicateEventCandidates,
  getEventAdmissions,
  getEventExports,
  requestEventExport,
} from "../api/eventApi";
import { EventPage } from "./EventPage";

vi.mock("../api/eventApi", () => ({
  admitEventMembership: vi.fn(),
  createEvent: vi.fn(),
  deleteEvent: vi.fn(),
  getDuplicateEventCandidates: vi.fn(),
  getEvent: vi.fn(),
  getEventAdmissions: vi.fn(),
  getEventExports: vi.fn(),
  getDeletedEvents: vi.fn(),
  getEvents: vi.fn(),
  getPersonEvents: vi.fn(),
  requestEventExport: vi.fn(),
  authorizeEventExportDownload: vi.fn(),
  revokeEventAdmission: vi.fn(),
  restoreEvent: vi.fn(),
  updateEvent: vi.fn(),
}));
vi.mock("@/features/albums/api/albumApi", () => ({ createAlbum: vi.fn() }));
vi.mock("@/features/invitations/api/invitationApi", () => ({
  issueInvitation: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("EventPage", () => {
  it("renders the narrow Guest landing path without family management queries", async () => {
    vi.mocked(getEvent).mockResolvedValue({
      id: "event-1",
      name: "Family wedding",
      description: "Photographs shared by the couple",
      starts_on: "2026-08-25",
      ends_on: null,
      location: "York",
      status: "active",
      created_by: 1,
      creator: { id: 1, name: "David" },
      permissions: {
        can_update: false,
        can_manage_admissions: false,
        can_review_duplicates: false,
        can_manage_exports: false,
        can_delete: false,
        can_restore: false,
        can_create_album: false,
      },
      albums: [
        {
          id: "album-1",
          name: "Wedding photographs",
          visibility: "family_space",
          guest_participation: "view",
        },
      ],
      attendees: [],
    });

    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/events/:eventId",
          element: <EventPage />,
        },
      ],
      { initialEntries: ["/families/family-archive/events/event-1"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole("heading", { name: "Family wedding" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "Wedding photographs" }),
    ).toHaveAttribute("href", "/families/family-archive/albums/album-1");
    expect(screen.queryByText("Event access")).not.toBeInTheDocument();
    expect(screen.queryByText("Possible duplicates")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Back to Events" }),
    ).not.toBeInTheDocument();
    expect(getDuplicateEventCandidates).not.toHaveBeenCalled();
    expect(getEventAdmissions).not.toHaveBeenCalled();
    expect(getEventExports).not.toHaveBeenCalled();
  });

  it("uses the typed Event export hooks for the manager archive surface", async () => {
    vi.mocked(getEvent).mockResolvedValue({
      id: "event-1",
      name: "Family wedding",
      description: null,
      starts_on: "2026-08-25",
      ends_on: null,
      location: "York",
      status: "active",
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
      albums: [],
      attendees: [],
    });
    vi.mocked(getDuplicateEventCandidates).mockResolvedValue([]);
    vi.mocked(getEventAdmissions).mockResolvedValue([]);
    vi.mocked(getEventExports).mockResolvedValue([
      {
        id: "export-1",
        state: "ready",
        requested_by: 1,
        requester: { id: 1, name: "David" },
        photo_count: 3,
        byte_size: 1024,
        archive_sha256: "a".repeat(64),
        failure_reason: null,
        expires_at: "2026-08-26T12:00:00Z",
        created_at: "2026-08-25T12:00:00Z",
      },
    ]);
    vi.mocked(requestEventExport).mockResolvedValue({
      id: "export-2",
      state: "pending",
      requested_by: 1,
      requester: { id: 1, name: "David" },
      photo_count: null,
      byte_size: null,
      archive_sha256: null,
      failure_reason: null,
      expires_at: null,
      created_at: "2026-08-25T12:01:00Z",
    });

    const client = new QueryClient({
      defaultOptions: {
        queries: { retry: false },
        mutations: { retry: false },
      },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/events/:eventId",
          element: <EventPage />,
        },
      ],
      { initialEntries: ["/families/family-archive/events/event-1"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByRole("heading", { name: "Event archives" }),
    ).toBeInTheDocument();
    expect(
      await screen.findByText(/Archive requested by David: ready/),
    ).toBeInTheDocument();
    fireEvent.click(
      screen.getByRole("button", { name: "Create Event archive" }),
    );
    await waitFor(() => {
      expect(requestEventExport).toHaveBeenCalledWith(
        "family-archive",
        "event-1",
      );
    });
  });
});
