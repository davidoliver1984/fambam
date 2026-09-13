import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";
import { MemoryRouter, Route, Routes } from "react-router";

import { server } from "@/test/msw/server";

import { FamilyExportsPage } from "./FamilyExportsPage";

const apiBaseUrl = "http://localhost:8082";

function renderPage(role: "owner" | "member") {
  let personalRequested = false;
  server.use(
    http.get(`${apiBaseUrl}/api/families/oliver-family`, () =>
      HttpResponse.json({
        data: {
          id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
          slug: "oliver-family",
          name: "Oliver Family",
          status: "active",
          role,
        },
      }),
    ),
    http.get(`${apiBaseUrl}/api/families/oliver-family/exports`, () =>
      HttpResponse.json({
        data: [
          {
            id: "01KEXPORT00000000000000000",
            scope: "personal",
            state: "ready",
            photo_count: 12,
            byte_size: 4096,
            failure_reason: null,
            expires_at: "2026-09-11T12:00:00Z",
            created_at: "2026-09-10T12:00:00Z",
          },
          {
            id: "01KEXPORTFAILED00000000000",
            scope: "personal",
            state: "failed",
            photo_count: null,
            byte_size: null,
            failure_reason: "internal-provider-detail",
            expires_at: null,
            created_at: "2026-09-10T12:00:00Z",
          },
        ],
      }),
    ),
    http.post(
      `${apiBaseUrl}/api/families/oliver-family/exports/personal`,
      () => {
        personalRequested = true;
        return HttpResponse.json(
          { data: { id: "01KNEWEXPORT", scope: "personal", state: "pending" } },
          { status: 202 },
        );
      },
    ),
  );
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={["/families/oliver-family/exports"]}>
        <Routes>
          <Route
            path="/families/:familySlug/exports"
            element={<FamilyExportsPage />}
          />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  );

  return { personalWasRequested: () => personalRequested };
}

afterEach(cleanup);

describe("FamilyExportsPage", () => {
  it("shows status fields, a safe failure message and both Owner actions", async () => {
    const user = userEvent.setup();
    const request = renderPage("owner");

    expect(
      await screen.findByRole("heading", { name: "Exports" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Request full Family Space archive" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Download" }),
    ).toBeInTheDocument();
    expect(screen.getByText("4,096 bytes")).toBeInTheDocument();
    expect(screen.getByText(/could not be completed/i)).toBeInTheDocument();
    expect(
      screen.queryByText("internal-provider-detail"),
    ).not.toBeInTheDocument();

    await user.click(
      screen.getByRole("button", { name: "Request my personal archive" }),
    );
    expect(request.personalWasRequested()).toBe(true);
  });

  it("offers a Personal Export but not a full archive to a Member", async () => {
    renderPage("member");

    expect(
      await screen.findByRole("button", {
        name: "Request my personal archive",
      }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", {
        name: "Request full Family Space archive",
      }),
    ).not.toBeInTheDocument();
  });
});
