import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import {
  getPeople,
  getPerson,
  getPersonProposals,
  resolvePersonProposal,
} from "../api/personApi";
import {
  getRelationshipProposals,
  getRelationships,
} from "../api/relationshipApi";
import type { Person } from "../types/person";
import { PersonPage } from "./PersonPage";

vi.mock("../api/personApi", () => ({
  getPeople: vi.fn(),
  getPerson: vi.fn(),
  getPersonProposals: vi.fn(),
  proposePersonDetails: vi.fn(),
  resolvePersonProposal: vi.fn(),
  updatePerson: vi.fn(),
}));

vi.mock("../api/relationshipApi", () => ({
  createRelationship: vi.fn(),
  disputeRelationship: vi.fn(),
  getRelationshipProposals: vi.fn(),
  getRelationships: vi.fn(),
  proposeRelationship: vi.fn(),
  removeRelationship: vi.fn(),
  replaceRelationship: vi.fn(),
  resolveRelationshipProposal: vi.fn(),
}));

vi.mock("../components/PersonAccountLinkPanel", () => ({
  PersonAccountLinkPanel: () => <p>Account link panel</p>,
}));

const person: Person = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada Oliver",
  alternate_names: ["Ada Smith"],
  identity_status: "confirmed",
  birth_date: { precision: "year", value: "1948" },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: "Family historian",
  account_link: null,
  redirected_from_person_id: null,
  created_at: "2026-08-06T10:00:00Z",
  updated_at: "2026-08-06T10:00:00Z",
  permissions: {
    can_update_authoritatively: false,
    can_propose_changes: true,
    can_resolve_proposals: false,
    can_propose_account_link: true,
    can_manage_account_link: false,
    can_propose_relationships: true,
    can_manage_relationships: false,
    can_propose_merge: true,
    can_manage_merge: false,
  },
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [
      {
        path: "/families/:familySlug/people/:personId",
        element: <PersonPage />,
      },
    ],
    { initialEntries: [`/families/oliver-family/people/${person.id}`] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPeople).mockResolvedValue([person]);
  vi.mocked(getRelationships).mockResolvedValue([]);
  vi.mocked(getRelationshipProposals).mockResolvedValue([]);
  vi.mocked(getPersonProposals).mockResolvedValue([]);
});

afterEach(() => {
  cleanup();
  vi.mocked(getPerson).mockReset();
  vi.mocked(getPeople).mockReset();
  vi.mocked(getRelationships).mockReset();
  vi.mocked(getRelationshipProposals).mockReset();
  vi.mocked(getPersonProposals).mockReset();
  vi.mocked(resolvePersonProposal).mockReset();
});

describe("PersonPage", () => {
  it("shows Members the proposal path rather than authoritative editing", async () => {
    vi.mocked(getPerson).mockResolvedValue(person);
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "Ada Oliver" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Propose changes" }),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("button", { name: "Submit proposal" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Save changes" }),
    ).not.toBeInTheDocument();
  });

  it("shows Owners and Administrators the authoritative edit path", async () => {
    vi.mocked(getPerson).mockResolvedValue({
      ...person,
      permissions: {
        can_update_authoritatively: true,
        can_propose_changes: true,
        can_resolve_proposals: true,
        can_propose_account_link: true,
        can_manage_account_link: true,
        can_propose_relationships: true,
        can_manage_relationships: true,
        can_propose_merge: true,
        can_manage_merge: true,
      },
    });
    renderPage();
    expect(
      await screen.findByRole("button", { name: "Save changes" }),
    ).toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Submit proposal" }),
    ).not.toBeInTheDocument();
  });

  it("lets an authoritative reviewer discover and approve a pending proposal", async () => {
    const user = userEvent.setup();
    vi.mocked(getPerson).mockResolvedValue({
      ...person,
      permissions: {
        can_update_authoritatively: true,
        can_propose_changes: true,
        can_resolve_proposals: true,
        can_propose_account_link: true,
        can_manage_account_link: true,
        can_propose_relationships: true,
        can_manage_relationships: true,
        can_propose_merge: true,
        can_manage_merge: true,
      },
    });
    vi.mocked(getPersonProposals)
      .mockResolvedValueOnce([
        {
          id: "01K40000000000000000000000",
          person_id: person.id,
          changes: { preferred_name: "Ada Jones" },
          status: "pending",
          proposed_by: 2,
          resolved_by: null,
          resolved_at: null,
          created_at: "2026-08-06T10:00:00Z",
        },
      ])
      .mockResolvedValue([]);
    vi.mocked(resolvePersonProposal).mockResolvedValue({
      id: "01K40000000000000000000000",
      person_id: person.id,
      changes: { preferred_name: "Ada Jones" },
      status: "approved",
      proposed_by: 2,
      resolved_by: 1,
      resolved_at: "2026-08-06T11:00:00Z",
      created_at: "2026-08-06T10:00:00Z",
    });

    renderPage();
    expect(await screen.findByText("Ada Jones")).toBeInTheDocument();
    await user.click(screen.getByRole("button", { name: "Approve" }));

    await waitFor(() => {
      expect(resolvePersonProposal).toHaveBeenCalledWith(
        "oliver-family",
        person.id,
        "01K40000000000000000000000",
        "approve",
      );
      expect(
        vi.mocked(getPersonProposals).mock.calls.length,
      ).toBeGreaterThanOrEqual(2);
    });
  });
});
