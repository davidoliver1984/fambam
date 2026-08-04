import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router";

import { server } from "@/test/msw/server";

import { FamilySpacePage } from "./FamilySpacePage";

vi.mock("@/features/invitations/pages/InvitationManagement", () => ({
  InvitationManagement: ({ familySlug }: { familySlug: string }) => (
    <p>Invitations for {familySlug}</p>
  ),
}));

const apiBaseUrl = "http://localhost:8082";

function renderPage(path: string) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={[path]}>
        <Routes>
          <Route path="/families/:familySlug" element={<FamilySpacePage />} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("FamilySpacePage", () => {
  it("derives the active Family Space from the URL", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family`, () =>
        HttpResponse.json({
          data: {
            id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
            slug: "oliver-family",
            name: "Oliver Family",
            status: "active",
            role: "owner",
          },
        }),
      ),
    );

    renderPage("/families/oliver-family");

    expect(
      await screen.findByRole("heading", { name: "Oliver Family" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("Invitations for oliver-family"),
    ).toBeInTheDocument();
  });

  it("renders the same unavailable state for a tenant 404", async () => {
    server.use(
      http.get(`${apiBaseUrl}/api/families/private-family`, () =>
        HttpResponse.json({ message: "Not Found." }, { status: 404 }),
      ),
    );

    renderPage("/families/private-family");

    expect(
      await screen.findByRole("heading", { name: "Family Space not found" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/unavailable or you no longer have access/i),
    ).toBeInTheDocument();
  });
});
