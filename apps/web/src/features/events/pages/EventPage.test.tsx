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
  getEventRsvps,
  updateEvent,
} from "../api/eventApi";
import { getCurrentUser } from "@/features/account/api/accountApi";
import { getFamilyMemberships } from "@/features/people/api/accountLinkApi";
import { EventPage } from "./EventPage";
import { getSearchSuggestions } from "@/features/search/api/searchApi";

vi.mock("../api/eventApi", () => ({
  admitEventMembership: vi.fn(),
  createEvent: vi.fn(),
  deleteEvent: vi.fn(),
  getDuplicateEventCandidates: vi.fn(),
  getEvent: vi.fn(),
  getEventAdmissions: vi.fn(),
  getEventRsvps: vi.fn(),
  getEventExports: vi.fn(),
  getDeletedEvents: vi.fn(),
  getEvents: vi.fn(),
  getPersonEvents: vi.fn(),
  requestEventExport: vi.fn(),
  authorizeEventExportDownload: vi.fn(),
  revokeEventAdmission: vi.fn(),
  restoreEvent: vi.fn(),
  updateEvent: vi.fn(),
  updateEventRsvp: vi.fn(),
}));
vi.mock("@/features/account/api/accountApi", () => ({
  getCurrentUser: vi.fn(),
}));
vi.mock("@/features/people/api/accountLinkApi", () => ({
  getFamilyMemberships: vi.fn(),
}));
vi.mock("@/features/albums/api/albumApi", () => ({ createAlbum: vi.fn() }));
vi.mock("@/features/invitations/api/invitationApi", () => ({
  issueInvitation: vi.fn(),
}));
vi.mock("@/features/search/api/searchApi", () => ({
  getSearchSuggestions: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

vi.mocked(getCurrentUser).mockResolvedValue({
  id: 1,
  name: "David",
  email: "david@example.test",
  timezone: "Europe/London",
  email_verified_at: null,
  can_create_family_spaces: false,
  two_factor_enabled: false,
});
vi.mocked(getEventRsvps).mockResolvedValue({
  going: [],
  pending: [],
  not_attending: [],
});
vi.mocked(getFamilyMemberships).mockResolvedValue([]);
vi.mocked(getSearchSuggestions).mockResolvedValue([]);

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
      tags: [{ id: "tag-1", label: "Wedding" }],
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
    ).toHaveAttribute(
      "href",
      "/families/family-archive/albums/album-1?eventId=event-1",
    );
    expect(screen.queryByText("Event access")).not.toBeInTheDocument();
    expect(screen.queryByText("Possible duplicates")).not.toBeInTheDocument();
    expect(
      screen.queryByRole("link", { name: "Back to Events" }),
    ).not.toBeInTheDocument();
    expect(getDuplicateEventCandidates).not.toHaveBeenCalled();
    expect(getEventAdmissions).not.toHaveBeenCalled();
    expect(getEventExports).not.toHaveBeenCalled();
    expect(screen.getByText("Wedding")).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Add tag" }),
    ).not.toBeInTheDocument();
  });

  it("uses the typed Event export hooks for the manager archive surface", async () => {
    vi.mocked(getFamilyMemberships).mockResolvedValue([
      {
        id: "membership-2",
        user: { id: 2, name: "Guest Mercer", email: "guest@example.test" },
        role: "guest",
        state: "active",
        removed_at: null,
      },
    ]);
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
      tags: [],
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
      await screen.findByRole("option", { name: "Guest Mercer (guest)" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByLabelText("Admit an existing membership ID"),
    ).not.toBeInTheDocument();
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

  it("renders persisted tags and adds and removes them through the Event update mutation", async () => {
    const item = {
      id: "event-1",
      name: "Blackpool holiday",
      description: "A bright, blustery week by the sea.",
      starts_on: "1986-08-12",
      ends_on: "1986-08-19",
      location: "Blackpool",
      status: "completed" as const,
      created_by: 1,
      creator: { id: 1, name: "David" },
      tags: [
        { id: "tag-1", label: "Blackpool" },
        { id: "tag-2", label: "Seaside" },
      ],
      permissions: {
        can_update: true,
        can_manage_admissions: false,
        can_review_duplicates: false,
        can_manage_exports: false,
        can_delete: false,
        can_restore: false,
        can_create_album: false,
      },
      albums: [],
      attendees: [],
    };
    vi.mocked(getEvent).mockResolvedValue(item);
    vi.mocked(getSearchSuggestions).mockResolvedValue([
      { id: "tag-3", label: "Family holiday" },
    ]);
    vi.mocked(updateEvent).mockResolvedValue({
      ...item,
      tags: [item.tags[0], { id: "tag-3", label: "Family holiday" }],
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

    expect(await screen.findByText("Blackpool")).toBeInTheDocument();
    expect(screen.getByText("Seaside")).toBeInTheDocument();
    fireEvent.click(screen.getByRole("button", { name: "Add tag" }));
    fireEvent.click(screen.getByRole("button", { name: "Remove Seaside" }));
    fireEvent.change(screen.getByLabelText("Add or reuse a tag"), {
      target: { value: "Fam" },
    });
    fireEvent.click(
      await screen.findByRole("button", { name: "Family holiday" }),
    );
    fireEvent.click(screen.getByRole("button", { name: "Save tags" }));

    await waitFor(() => {
      expect(updateEvent).toHaveBeenCalledWith("family-archive", "event-1", {
        tags: ["Blackpool", "Family holiday"],
      });
    });
  });

  it("keeps the tag editor open and shows an error when persistence fails", async () => {
    vi.mocked(getEvent).mockResolvedValue({
      id: "event-1",
      name: "Family event",
      description: null,
      starts_on: null,
      ends_on: null,
      location: null,
      status: "planned",
      created_by: 1,
      creator: { id: 1, name: "David" },
      tags: [],
      permissions: {
        can_update: true,
        can_manage_admissions: false,
        can_review_duplicates: false,
        can_manage_exports: false,
        can_delete: false,
        can_restore: false,
        can_create_album: false,
      },
      albums: [],
      attendees: [],
    });
    vi.mocked(updateEvent).mockRejectedValue(new Error("save failed"));
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

    fireEvent.click(await screen.findByRole("button", { name: "Add tag" }));
    fireEvent.change(screen.getByLabelText("Add or reuse a tag"), {
      target: { value: "Holiday" },
    });
    fireEvent.click(screen.getByRole("button", { name: "Add" }));
    fireEvent.click(screen.getByRole("button", { name: "Save tags" }));
    expect(
      await screen.findByText("Event tags could not be saved."),
    ).toHaveAttribute("role", "alert");
    expect(screen.getByLabelText("Add or reuse a tag")).toBeInTheDocument();
  });
});
