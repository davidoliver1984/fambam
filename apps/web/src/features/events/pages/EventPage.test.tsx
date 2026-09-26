import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  waitFor,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  admitEventMembership,
  getEvent,
  getDuplicateEventCandidates,
  getEventAdmissions,
  getEventExports,
  getEventRsvps,
  updateEvent,
} from "../api/eventApi";
import { issueInvitation } from "@/features/invitations/api/invitationApi";
import { getCurrentUser } from "@/features/account/api/accountApi";
import { getFamilyMemberships } from "@/features/people/api/accountLinkApi";
import { getAlbums } from "@/features/albums/api/albumApi";
import { getSearchSuggestions } from "@/features/search/api/searchApi";
import { EventPage } from "./EventPage";

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
vi.mock("@/features/albums/api/albumApi", () => ({
  getAlbums: vi.fn(),
  createAlbum: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
}));
vi.mock("@/features/search/api/searchApi", () => ({
  getSearchSuggestions: vi.fn(),
  searchArchive: vi.fn().mockResolvedValue({ items: [], next_cursor: null }),
}));
vi.mock("@/features/invitations/api/invitationApi", () => ({
  issueInvitation: vi.fn(),
}));
vi.mock("@/features/love/api/loveApi", () => ({
  getLoveSummary: vi.fn().mockResolvedValue({
    count: 0,
    loved_by_me: false,
    reactors: [],
  }),
  removeLove: vi.fn(),
  saveLove: vi.fn(),
}));
vi.mock("@/features/collections/api/collectionApi", () => ({
  addCollectionPhoto: vi.fn(),
  createCollection: vi.fn(),
  getCollections: vi.fn().mockResolvedValue([]),
  populateCollection: vi.fn(),
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
vi.mocked(getAlbums).mockResolvedValue([]);
vi.mocked(getSearchSuggestions).mockResolvedValue([]);
vi.mocked(updateEvent).mockImplementation((_familySlug, _eventId, input) =>
  Promise.resolve({
    id: "event-1",
    name: "Family wedding",
    description: null,
    starts_on: null,
    ends_on: null,
    location: null,
    status: "active",
    updated_at: "2026-08-25T10:00:00+00:00",
    created_by: 1,
    creator: { id: 1, name: "David" },
    people: [],
    tags: (input.tags ?? []).map((label, index) => ({
      id: `tag-${String(index)}`,
      label,
    })),
    permissions: {
      can_update: true,
      can_manage_admissions: true,
      can_review_duplicates: true,
      can_manage_exports: true,
      can_delete: true,
      can_restore: false,
      can_create_album: true,
    },
  }),
);

function renderEventPage() {
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
}

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
      updated_at: "2026-08-25T10:00:00+00:00",
      created_by: 1,
      creator: { id: 1, name: "David" },
      tags: [{ id: "tag-1", label: "Wedding" }],
      people: [],
      permissions: {
        can_update: false,
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
    vi.mocked(getAlbums).mockResolvedValue([
      {
        id: "album-1",
        name: "Wedding photographs",
        description: null,
        visibility: "family_space",
        created_by: 1,
        creator: { id: 1, name: "Album creator" },
        created_at: "2026-09-01T10:00:00+00:00",
        updated_at: "2026-09-02T10:00:00+00:00",
        photo_count: 0,
        event_id: "event-1",
        event: { id: "event-1", name: "Family wedding", starts_on: null },
        guest_participation: "view",
        photos: [],
        grants: [],
        permissions: { can_manage: false, can_contribute: false },
      },
    ]);

    renderEventPage();

    expect(
      await screen.findByRole("heading", { name: "Family wedding" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /Wedding photographs/ }),
    ).toHaveAttribute(
      "href",
      "/families/family-archive/albums/album-1?eventId=event-1",
    );
    expect(
      screen.queryByRole("button", { name: "Invite people" }),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Edit event" }),
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
      ends_on: "2026-08-28",
      location: "York",
      status: "active",
      updated_at: "2026-08-25T10:00:00+00:00",
      created_by: 1,
      creator: { id: 1, name: "David" },
      people: [
        { id: "person-1", name: "Ada Mercer" },
        { id: "person-2", name: "William Mercer" },
      ],
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
      tags: [{ id: "tag-family", label: "Family" }],
    });
    vi.mocked(getEventAdmissions).mockResolvedValue([
      {
        id: "admission-1",
        membership_id: "membership-1",
        user: {
          id: 1,
          name: "David Mercer",
          email: "david@example.test",
          person_id: "person-david",
        },
        role: "owner",
        admitted_at: "2026-09-24T12:00:00Z",
        revoked_at: null,
        rsvp_status: "going",
        valid_until: "2026-10-24T12:00:00Z",
      },
    ]);

    renderEventPage();
    const user = userEvent.setup();

    expect(
      await screen.findByRole("link", { name: "View David Mercer" }),
    ).toHaveAttribute("href", "/families/family-archive/people/person-david");
    const inviteButtons = await screen.findAllByRole("button", {
      name: "Invite people",
    });
    await user.click(inviteButtons[0]);
    expect(
      await screen.findByRole("dialog", {
        name: "Invite people to this Event",
      }),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Remove David Mercer" }),
    );
    await user.type(
      screen.getByRole("textbox", {
        name: "Search People or enter an email address",
      }),
      "Guest",
    );
    expect(screen.getByText("Guest Mercer")).toBeInTheDocument();
    expect(screen.getByText("guest@example.test")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Add" }));
    expect(
      screen.getByRole("combobox", { name: "Role for Guest Mercer" }),
    ).toHaveValue("guest");
    expect(
      screen.getByRole("button", { name: "Send 1 invitation" }),
    ).toBeEnabled();
    await user.selectOptions(
      screen.getByRole("combobox", { name: "Role for Guest Mercer" }),
      "contributor",
    );
    expect(
      screen.getByRole("combobox", { name: "Role for Guest Mercer" }),
    ).toHaveValue("contributor");
    await user.click(
      screen.getByRole("button", { name: "Remove Guest Mercer" }),
    );
    expect(
      screen.getByRole("button", { name: "Send 0 invitations" }),
    ).toBeDisabled();
    await user.type(
      screen.getByRole("textbox", {
        name: "Search People or enter an email address",
      }),
      "Guest",
    );
    await user.click(screen.getByRole("button", { name: "Add" }));
    await user.click(screen.getByRole("button", { name: "Send 1 invitation" }));
    expect(await screen.findByText("1 invitation sent")).toBeInTheDocument();
    expect(admitEventMembership).toHaveBeenCalledWith(
      "family-archive",
      "event-1",
      "membership-2",
    );

    await user.click(inviteButtons[0]);
    await user.click(
      screen.getByRole("button", { name: "Remove David Mercer" }),
    );
    await user.type(
      screen.getByRole("textbox", {
        name: "Search People or enter an email address",
      }),
      "new.guest@example.test",
    );
    await user.click(screen.getByRole("button", { name: "Send 1 invitation" }));
    expect(issueInvitation).toHaveBeenCalledWith("family-archive", {
      email: "new.guest@example.test",
      event_id: "event-1",
    });

    expect(screen.getByText("25–28 August 2026")).toBeInTheDocument();
    expect(screen.getByText("3")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /Write a story/i }),
    ).toHaveAttribute(
      "href",
      "/families/family-archive/stories/new?type=event&subjectId=event-1",
    );

    await user.click(screen.getByRole("button", { name: "Event options" }));
    await user.click(
      screen.getByRole("menuitem", { name: "Change cover photo" }),
    );
    expect(await screen.findByText("Cover picker opened")).toBeInTheDocument();

    await user.click(screen.getByRole("button", { name: "Event options" }));
    await user.click(screen.getByRole("menuitem", { name: "Delete event" }));
    expect(
      await screen.findByRole("dialog", { name: "Delete “Family wedding”?" }),
    ).toBeInTheDocument();
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
      updated_at: "2026-08-19T10:00:00+00:00",
      created_by: 1,
      creator: { id: 1, name: "David" },
      people: [],
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

    renderEventPage();

    expect(
      await screen.findByText("Blackpool", { selector: ".event-tag" }),
    ).toBeInTheDocument();
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
      updated_at: "2026-08-25T10:00:00+00:00",
      created_by: 1,
      creator: { id: 1, name: "David" },
      tags: [],
      people: [],
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

    renderEventPage();

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
