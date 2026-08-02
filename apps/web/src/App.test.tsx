import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "vitest";

import { App } from "./App";
import { InvitationAcceptanceForm } from "./features/invitations/components/InvitationAcceptanceForm";

afterEach(cleanup);

describe("App", () => {
  it("renders the family archive foundation", () => {
    render(<App path="/" />);

    expect(
      screen.getByRole("heading", {
        name: "A private home for family memories.",
      }),
    ).toBeInTheDocument();
  });

  it("exposes a health view", () => {
    render(<App path="/health" />);

    expect(
      screen.getByRole("heading", { name: "Web application healthy" }),
    ).toBeInTheDocument();
  });

  it("renders password-manager-friendly login fields", () => {
    render(<App path="/login" />);

    expect(screen.getByLabelText("Email address")).toHaveAttribute(
      "autocomplete",
      "email",
    );
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "autocomplete",
      "current-password",
    );
    expect(screen.queryByText(/create an account/i)).not.toBeInTheDocument();
  });

  it("keeps the invited email authoritative on the acceptance form", () => {
    const queryClient = new QueryClient({
      defaultOptions: { mutations: { retry: false } },
    });

    render(
      <QueryClientProvider client={queryClient}>
        <InvitationAcceptanceForm
          claim={{
            claim_token: "claim-token",
            email: "relative@example.test",
            expires_at: "2026-08-02T12:00:00Z",
          }}
        />
      </QueryClientProvider>,
    );

    expect(screen.getByText("relative@example.test")).toBeInTheDocument();
    expect(
      screen.queryByRole("textbox", { name: /email/i }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "autocomplete",
      "new-password",
    );
  });
});
