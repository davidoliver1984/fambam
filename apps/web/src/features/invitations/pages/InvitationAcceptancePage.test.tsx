import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { StrictMode } from "react";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  acceptInvitation,
  exchangeInvitationToken,
} from "../api/invitationApi";
import { InvitationAcceptancePage } from "./InvitationAcceptancePage";

vi.mock("../api/invitationApi", () => ({
  acceptInvitation: vi.fn(),
  exchangeInvitationToken: vi.fn(),
}));

beforeEach(() => {
  window.location.hash = "token=raw-invitation-token";
  vi.mocked(exchangeInvitationToken).mockResolvedValue({
    claim_token: "opaque-claim",
    email: "relative@example.test",
    family_space_name: "Oliver Family",
    inviter: { name: "David Oliver", avatar_url: null },
    event: null,
    role: "member",
    existing_account: false,
    expires_at: "2026-08-02T12:15:00Z",
  });
});

afterEach(() => {
  cleanup();
  vi.mocked(acceptInvitation).mockReset();
  vi.mocked(exchangeInvitationToken).mockReset();
});

describe("InvitationAcceptancePage", () => {
  it("exchanges a fragment token once under Strict Mode", async () => {
    const queryClient = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });

    render(
      <StrictMode>
        <QueryClientProvider client={queryClient}>
          <InvitationAcceptancePage />
        </QueryClientProvider>
      </StrictMode>,
    );

    expect(
      await screen.findByText("relative@example.test"),
    ).toBeInTheDocument();
    expect(exchangeInvitationToken).toHaveBeenCalledTimes(1);
    expect(window.location.hash).toBe("");
  });

  it("offers a retry when a valid-looking link cannot be checked", async () => {
    vi.mocked(exchangeInvitationToken).mockRejectedValueOnce(
      new Error("network"),
    );
    const queryClient = new QueryClient({
      defaultOptions: { mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <InvitationAcceptancePage />
      </QueryClientProvider>,
    );

    expect(
      await screen.findByText(
        "We couldn’t check this invitation. Please try again.",
      ),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Try invitation again" }),
    ).toBeInTheDocument();
  });
});
