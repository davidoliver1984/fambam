import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  render,
  screen,
  type RenderResult,
} from "@testing-library/react";
import type { ReactNode } from "react";
import { afterEach, describe, expect, it } from "vitest";

import { App } from "./App";
import { InvitationAcceptanceForm } from "./features/invitations/components/InvitationAcceptanceForm";

afterEach(cleanup);

function renderWithQuery(children: ReactNode): RenderResult {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>{children}</QueryClientProvider>,
  );
}

describe("App", () => {
  it("renders the family archive foundation", () => {
    renderWithQuery(<App path="/" />);

    expect(
      screen.getByRole("heading", {
        name: "A private home for family memories.",
      }),
    ).toBeInTheDocument();
  });

  it("exposes a health view", () => {
    renderWithQuery(<App path="/health" />);

    expect(
      screen.getByRole("heading", { name: "Web application healthy" }),
    ).toBeInTheDocument();
  });

  it("renders password-manager-friendly login fields", () => {
    renderWithQuery(<App path="/login" />);

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
    renderWithQuery(
      <InvitationAcceptanceForm
        claim={{
          claim_token: "claim-token",
          email: "relative@example.test",
          expires_at: "2026-08-02T12:00:00Z",
        }}
      />,
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
