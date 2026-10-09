import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  fireEvent,
  render,
  screen,
  type RenderResult,
} from "@testing-library/react";
import { http, HttpResponse } from "msw";
import type { ReactNode } from "react";
import { MemoryRouter } from "react-router";
import { afterEach, describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import { App } from "./App";
import { InvitationAcceptanceForm } from "./features/invitations/components/InvitationAcceptanceForm";

afterEach(cleanup);

function renderWithQuery(children: ReactNode, path = "/"): RenderResult {
  const queryClient = new QueryClient({
    defaultOptions: {
      queries: { retry: false },
      mutations: { retry: false },
    },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>{children}</MemoryRouter>
    </QueryClientProvider>,
  );
}

describe("App", () => {
  it("renders the family archive foundation", () => {
    renderWithQuery(<App />);

    expect(
      screen.getByRole("heading", {
        name: "A private home for family memories.",
      }),
    ).toBeInTheDocument();
  });

  it("exposes a health view", () => {
    renderWithQuery(<App />, "/health");

    expect(
      screen.getByRole("heading", { name: "Web application healthy" }),
    ).toBeInTheDocument();
  });

  it("exposes the UI playground only in development", async () => {
    renderWithQuery(<App />, "/ui-playground");

    expect(
      await screen.findByRole("heading", { name: "Interface elements" }),
    ).toBeInTheDocument();
  });

  it("renders password-manager-friendly login fields", () => {
    renderWithQuery(<App />, "/login");

    expect(screen.getByLabelText("Email address")).toHaveAttribute(
      "autocomplete",
      "email",
    );
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "autocomplete",
      "current-password",
    );
    expect(screen.getByLabelText("Password")).toHaveAttribute(
      "type",
      "password",
    );
    fireEvent.click(screen.getByRole("button", { name: "Show password" }));
    expect(screen.getByLabelText("Password")).toHaveAttribute("type", "text");
    expect(
      screen.getByRole("button", { name: "Hide password" }),
    ).toBeInTheDocument();
    expect(screen.queryByText(/create an account/i)).not.toBeInTheDocument();
  });

  it("routes an existing authenticated session away from sign in", async () => {
    server.use(
      http.get("http://localhost:8082/api/user", () =>
        HttpResponse.json({
          data: {
            id: 1,
            name: "David Mercer",
            about: null,
            email: "mercer.owner@fambam.test",
            pending_email: null,
            pending_email_requested_at: null,
            avatar: null,
            timezone: "Europe/London",
            email_verified_at: "2026-10-09T07:00:00Z",
            can_create_family_spaces: true,
            two_factor_enabled: true,
          },
        }),
      ),
    );

    renderWithQuery(<App />, "/login");

    expect(
      await screen.findByRole("heading", { name: "Your account" }),
    ).toBeInTheDocument();
  });

  it("keeps the invited email authoritative on the acceptance form", () => {
    renderWithQuery(
      <InvitationAcceptanceForm
        claim={{
          claim_token: "claim-token",
          email: "relative@example.test",
          family_space_name: "Oliver Family",
          inviter: { name: "David Oliver", avatar_url: null },
          event: null,
          role: "member",
          existing_account: false,
          expires_at: "2026-08-02T12:00:00Z",
        }}
      />,
    );

    expect(screen.getByText("relative@example.test")).toBeInTheDocument();
    expect(screen.getByText("David Oliver")).toBeInTheDocument();
    expect(
      screen.queryByRole("textbox", { name: /email/i }),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText("Create a password")).toHaveAttribute(
      "autocomplete",
      "new-password",
    );
    expect(screen.queryByLabelText("Timezone")).not.toBeInTheDocument();
    expect(
      screen.getAllByRole("button", { name: "Show password" }),
    ).toHaveLength(2);
    expect(screen.getByText("Add a password")).toBeInTheDocument();
  });

  it("redirects an unauthenticated Family Space route to sign in", async () => {
    server.use(
      http.get("http://localhost:8082/api/user", () =>
        HttpResponse.json({ message: "Unauthenticated." }, { status: 401 }),
      ),
    );

    renderWithQuery(<App />, "/families/private-family");

    expect(
      await screen.findByRole("heading", { name: "Welcome back" }),
    ).toBeInTheDocument();
  });
});
