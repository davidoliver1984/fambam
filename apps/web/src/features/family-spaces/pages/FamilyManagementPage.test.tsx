import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { server } from "@/test/msw/server";

import { FamilyManagementPage } from "./FamilyManagementPage";

vi.mock("@/features/invitations/pages/InvitationManagement", () => ({
  InvitationManagement: () => (
    <section aria-label="Invitations">Invitation controls</section>
  ),
}));
vi.mock("../components/FamilySpaceDeletionPanel", () => ({
  FamilySpaceDeletionPanel: () => (
    <section aria-label="Deletion">Deletion controls</section>
  ),
}));

afterEach(cleanup);

describe("FamilyManagementPage", () => {
  it("shows membership, invitation and portability controls to an Owner", async () => {
    server.use(
      http.get("http://localhost:8082/api/families/mercer", () =>
        HttpResponse.json({
          data: {
            id: "family-1",
            slug: "mercer",
            name: "Mercer Family",
            status: "active",
            role: "owner",
          },
        }),
      ),
      http.get("http://localhost:8082/api/families/mercer/memberships", () =>
        HttpResponse.json({
          data: [
            {
              id: "member-1",
              user: { id: 2, name: "Maya", email: "maya@example.test" },
              role: "member",
              state: "active",
              removed_at: null,
            },
          ],
        }),
      ),
    );
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    const router = createMemoryRouter(
      [
        {
          path: "/families/:familySlug/settings",
          element: <FamilyManagementPage />,
        },
      ],
      { initialEntries: ["/families/mercer/settings"] },
    );
    render(
      <QueryClientProvider client={client}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
    );
    expect(await screen.findByText("Maya")).toBeInTheDocument();
    expect(screen.getByLabelText("Invitations")).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: /exports and downloads/i }),
    ).toHaveAttribute("href", "/families/mercer/exports");
  });
});
