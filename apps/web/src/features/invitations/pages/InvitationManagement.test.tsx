import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  acceptInvitation,
  getInvitations,
  issueInvitation,
  transitionInvitation,
} from "../api/invitationApi";
import type { Invitation } from "../types/invitation";
import { InvitationManagement } from "./InvitationManagement";

vi.mock("../api/invitationApi", () => ({
  acceptInvitation: vi.fn(),
  getInvitations: vi.fn(),
  issueInvitation: vi.fn(),
  transitionInvitation: vi.fn(),
}));

const invitation: Invitation = {
  id: 7,
  email: "relative@example.test",
  status: "pending",
  expires_at: "2026-08-09T12:00:00Z",
  accepted_at: null,
  revoked_at: null,
  acceptable: true,
};

function renderManagement() {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <InvitationManagement />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getInvitations).mockResolvedValue([invitation]);
  vi.mocked(issueInvitation).mockResolvedValue(invitation);
});

afterEach(() => {
  cleanup();
  vi.mocked(acceptInvitation).mockReset();
  vi.mocked(getInvitations).mockReset();
  vi.mocked(issueInvitation).mockReset();
  vi.mocked(transitionInvitation).mockReset();
});

describe("InvitationManagement", () => {
  it("loads invitations through the feature query", async () => {
    renderManagement();

    expect(
      await screen.findByText("relative@example.test"),
    ).toBeInTheDocument();
    expect(getInvitations).toHaveBeenCalledTimes(1);
  });

  it("invalidates the invitation list after issuing an invitation", async () => {
    const user = userEvent.setup();
    renderManagement();
    await screen.findByText("relative@example.test");

    await user.type(
      screen.getByLabelText("Relative's email address"),
      "another@example.test",
    );
    await user.click(screen.getByRole("button", { name: "Send invitation" }));

    await waitFor(() => {
      expect(vi.mocked(issueInvitation).mock.calls[0]?.[0]).toBe(
        "another@example.test",
      );
      expect(getInvitations).toHaveBeenCalledTimes(2);
    });
    expect(screen.getByRole("status")).toHaveTextContent("Invitation sent.");
  });
});
