import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { http, HttpResponse } from "msw";
import { afterEach, describe, expect, it } from "vitest";

import { server } from "@/test/msw/server";

import type { FamilySpace } from "../types/familySpace";
import { FamilySpaceDeletionPanel } from "./FamilySpaceDeletionPanel";

const apiBaseUrl = "http://localhost:8082";

function renderPanel(familySpace: FamilySpace) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <FamilySpaceDeletionPanel familySpace={familySpace} />
    </QueryClientProvider>,
  );
}

afterEach(cleanup);

describe("FamilySpaceDeletionPanel", () => {
  it("offers the archive prominently during the deletion grace period without blocking cancellation", async () => {
    let archiveRequested = false;
    let deletionCancelled = false;
    server.use(
      http.get(`${apiBaseUrl}/api/families/oliver-family/exports`, () =>
        HttpResponse.json({ data: [] }),
      ),
      http.post(`${apiBaseUrl}/api/families/oliver-family/exports/full`, () => {
        archiveRequested = true;
        return HttpResponse.json(
          {
            data: {
              id: "01KEXPORT00000000000000000",
              scope: "family_space_full",
              state: "pending",
            },
          },
          { status: 202 },
        );
      }),
      http.delete(`${apiBaseUrl}/api/families/oliver-family/deletion`, () => {
        deletionCancelled = true;
        return HttpResponse.json({ data: { status: "active" } });
      }),
    );

    const user = userEvent.setup();
    renderPanel({
      id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
      slug: "oliver-family",
      name: "Oliver Family",
      status: "deletion_requested",
      role: "owner",
      deletion: {
        requested_at: "2026-09-10T12:00:00Z",
        scheduled_at: "2026-09-24T12:00:00Z",
      },
    });

    expect(
      await screen.findByRole("heading", {
        name: "Download your family archive",
      }),
    ).toBeInTheDocument();
    expect(
      screen.getByText(/deletion remains scheduled whether or not/i),
    ).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Create family archive" }),
    );
    expect(archiveRequested).toBe(true);
    await user.click(
      screen.getByRole("button", { name: "Cancel Family Space deletion" }),
    );
    expect(deletionCancelled).toBe(true);
  });

  it("does not expose Owner deletion controls to other roles", () => {
    renderPanel({
      id: "01K1ZZZZZZZZZZZZZZZZZZZZZZ",
      slug: "oliver-family",
      name: "Oliver Family",
      status: "active",
      role: "administrator",
    });

    expect(
      screen.queryByRole("heading", { name: /delete this Family Space/i }),
    ).not.toBeInTheDocument();
  });
});
