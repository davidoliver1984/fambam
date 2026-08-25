import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  getEvent,
  getDuplicateEventCandidates,
  getEventAdmissions,
} from "../api/eventApi";
import { EventPage } from "./EventPage";

vi.mock("../api/eventApi", () => ({
  admitEventMembership: vi.fn(),
  createEvent: vi.fn(),
  deleteEvent: vi.fn(),
  getDuplicateEventCandidates: vi.fn(),
  getEvent: vi.fn(),
  getEventAdmissions: vi.fn(),
  getDeletedEvents: vi.fn(),
  getEvents: vi.fn(),
  getPersonEvents: vi.fn(),
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
  });
});
