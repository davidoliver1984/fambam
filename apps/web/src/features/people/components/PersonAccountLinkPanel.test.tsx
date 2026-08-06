import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  assignAccountLink,
  getAccountLinkClaims,
  getFamilyMemberships,
  proposeAccountLink,
  resolveAccountLinkClaim,
} from "../api/accountLinkApi";
import type { Person } from "../types/person";
import { PersonAccountLinkPanel } from "./PersonAccountLinkPanel";

vi.mock("../api/accountLinkApi", () => ({
  assignAccountLink: vi.fn(),
  getAccountLinkClaims: vi.fn(),
  getFamilyMemberships: vi.fn(),
  proposeAccountLink: vi.fn(),
  removeAccountLink: vi.fn(),
  resolveAccountLinkClaim: vi.fn(),
}));

const person: Person = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada Oliver",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
  account_link: null,
  created_at: "2026-08-06T12:00:00Z",
  updated_at: "2026-08-06T12:00:00Z",
  permissions: {
    can_update_authoritatively: false,
    can_propose_changes: true,
    can_resolve_proposals: false,
    can_propose_account_link: true,
    can_manage_account_link: false,
  },
};

function renderPanel(value: Person = person) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={queryClient}>
      <PersonAccountLinkPanel familySlug="oliver-family" person={value} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getAccountLinkClaims).mockResolvedValue([]);
  vi.mocked(getFamilyMemberships).mockResolvedValue([]);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PersonAccountLinkPanel", () => {
  it("lets a Member submit a self-claim without loading manager data", async () => {
    const user = userEvent.setup();
    vi.mocked(proposeAccountLink).mockResolvedValue({
      id: "01K40000000000000000000000",
      person_id: person.id,
      account: { id: 2, name: "Ada Oliver" },
      status: "pending",
      resolved_at: null,
      created_at: "2026-08-06T12:00:00Z",
    });
    renderPanel();

    await user.click(screen.getByRole("button", { name: "This Person is me" }));
    expect(await screen.findByRole("status")).toHaveTextContent(
      "submitted for review",
    );
    expect(proposeAccountLink).toHaveBeenCalledWith("oliver-family", person.id);
    expect(getAccountLinkClaims).not.toHaveBeenCalled();
    expect(getFamilyMemberships).not.toHaveBeenCalled();
  });

  it("lets a manager discover claims and assign an active membership", async () => {
    const user = userEvent.setup();
    const managerPerson: Person = {
      ...person,
      permissions: {
        ...person.permissions,
        can_update_authoritatively: true,
        can_resolve_proposals: true,
        can_manage_account_link: true,
      },
    };
    vi.mocked(getAccountLinkClaims).mockResolvedValue([
      {
        id: "01K40000000000000000000000",
        person_id: person.id,
        account: { id: 2, name: "Ada Oliver" },
        status: "pending",
        resolved_at: null,
        created_at: "2026-08-06T12:00:00Z",
      },
    ]);
    vi.mocked(getFamilyMemberships).mockResolvedValue([
      {
        id: "01K60000000000000000000000",
        user: { id: 2, name: "Ada Oliver", email: "ada@example.test" },
        role: "member",
        state: "active",
        removed_at: null,
      },
    ]);
    vi.mocked(assignAccountLink).mockResolvedValue({
      id: "01K50000000000000000000000",
      person_id: person.id,
      account: { id: 2, name: "Ada Oliver", is_current_user: false },
    });
    vi.mocked(resolveAccountLinkClaim).mockResolvedValue({
      id: "01K50000000000000000000000",
      person_id: person.id,
      account: { id: 2, name: "Ada Oliver", is_current_user: false },
    });
    renderPanel(managerPerson);

    expect(await screen.findByText("Ada Oliver")).toBeInTheDocument();
    await user.click(
      screen.getByRole("button", { name: "Approve self-claim" }),
    );
    await waitFor(() => {
      expect(resolveAccountLinkClaim).toHaveBeenCalledWith(
        "oliver-family",
        person.id,
        "01K40000000000000000000000",
        "approve",
      );
    });
    await user.selectOptions(
      screen.getByLabelText("Family Space member"),
      "01K60000000000000000000000",
    );
    await user.click(screen.getByRole("button", { name: "Save account link" }));

    await waitFor(() => {
      expect(assignAccountLink).toHaveBeenCalledWith(
        "oliver-family",
        person.id,
        "01K60000000000000000000000",
      );
    });
  });
});
