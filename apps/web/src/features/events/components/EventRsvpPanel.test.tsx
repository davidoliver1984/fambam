import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import { getCurrentUser } from "@/features/account/api/accountApi";
import { getEventRsvps, updateEventRsvp } from "../api/eventApi";
import { EventRsvpPanel } from "./EventRsvpPanel";

vi.mock("@/features/account/api/accountApi", () => ({
  getCurrentUser: vi.fn(),
}));
vi.mock("../api/eventApi", () => ({
  getEventRsvps: vi.fn(),
  updateEventRsvp: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={client}>
      <EventRsvpPanel familySlug="family-archive" eventId="event-1" />
    </QueryClientProvider>,
  );
}

describe("EventRsvpPanel", () => {
  it("lets an admitted participant change only their own RSVP", async () => {
    vi.mocked(getCurrentUser).mockResolvedValue({
      id: 2,
      name: "Guest",
      email: "guest@example.test",
      timezone: "Europe/London",
      email_verified_at: null,
      can_create_family_spaces: false,
      two_factor_enabled: false,
    });
    vi.mocked(getEventRsvps).mockResolvedValue({
      going: [],
      pending: [{ id: "admission-1", user: { id: 2, name: "Guest" } }],
      not_attending: [],
    });
    vi.mocked(updateEventRsvp).mockResolvedValue({
      id: "admission-1",
      membership_id: "membership-1",
      user: { id: 2, name: "Guest", email: "guest@example.test" },
      role: "guest",
      admitted_at: "2026-09-18T09:00:00Z",
      revoked_at: null,
      valid_until: "2026-10-18T09:00:00Z",
      rsvp_status: "going",
    });
    renderPanel();

    await userEvent
      .setup()
      .click(await screen.findByRole("button", { name: "Going" }));
    await waitFor(() => {
      expect(updateEventRsvp).toHaveBeenCalledWith(
        "family-archive",
        "event-1",
        "going",
      );
    });
    expect(
      await screen.findByText("Your response was saved."),
    ).toBeInTheDocument();
  });

  it("does not offer an RSVP control without an active admission", async () => {
    vi.mocked(getCurrentUser).mockResolvedValue({
      id: 3,
      name: "Member",
      email: "member@example.test",
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
    renderPanel();
    expect(
      await screen.findByText("Only admitted Event participants can respond."),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Going" }),
    ).not.toBeInTheDocument();
  });
});
