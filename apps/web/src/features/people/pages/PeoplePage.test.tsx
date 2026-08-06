import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { createPerson, getPeople } from "../api/personApi";
import type { Person } from "../types/person";
import { PeoplePage } from "./PeoplePage";

vi.mock("../api/personApi", () => ({
  createPerson: vi.fn(),
  getPeople: vi.fn(),
}));

const person: Person = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada Oliver",
  alternate_names: [],
  identity_status: "provisional",
  birth_date: { precision: "unknown", value: null },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
  created_at: "2026-08-06T10:00:00Z",
  updated_at: "2026-08-06T10:00:00Z",
  permissions: {
    can_update_authoritatively: false,
    can_propose_changes: true,
    can_resolve_proposals: false,
  },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/people", element: <PeoplePage /> }],
    { initialEntries: ["/families/oliver-family/people"] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPeople).mockResolvedValue([person]);
  vi.mocked(createPerson).mockResolvedValue(person);
});

afterEach(() => {
  cleanup();
  vi.mocked(getPeople).mockReset();
  vi.mocked(createPerson).mockReset();
});

describe("PeoplePage", () => {
  it("renders query success and provisional identity state", async () => {
    renderPage();
    expect(await screen.findByText("Ada Oliver")).toBeInTheDocument();
    expect(screen.getByText("Provisional")).toBeInTheDocument();
    expect(getPeople).toHaveBeenCalledWith(
      "oliver-family",
      expect.any(AbortSignal),
    );
  });

  it("renders an empty directory state", async () => {
    vi.mocked(getPeople).mockResolvedValue([]);
    renderPage();
    expect(
      await screen.findByText(/No People have been added/i),
    ).toBeInTheDocument();
  });

  it("renders query errors", async () => {
    vi.mocked(getPeople).mockRejectedValue(new Error("unavailable"));
    renderPage();
    expect(await screen.findByRole("alert")).toHaveTextContent(
      "The people directory could not be loaded.",
    );
  });

  it("creates through the feature mutation and refreshes the list", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText("Ada Oliver");
    await user.type(screen.getByLabelText("Preferred name"), "Grace Oliver");
    await user.click(screen.getByRole("button", { name: "Add Person" }));

    await waitFor(() => {
      expect(createPerson).toHaveBeenCalledWith(
        "oliver-family",
        expect.objectContaining({ preferred_name: "Grace Oliver" }),
      );
      expect(getPeople).toHaveBeenCalledTimes(2);
    });
    expect(screen.getByRole("status")).toHaveTextContent("Person added.");
  });
});
