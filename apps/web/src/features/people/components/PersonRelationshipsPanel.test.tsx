import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  createRelationship,
  getRelationshipProposals,
  getRelationships,
  proposeRelationship,
} from "../api/relationshipApi";
import type { Person } from "../types/person";
import { PersonRelationshipsPanel } from "./PersonRelationshipsPanel";

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

const person: Person = {
  id: "01K30000000000000000000000",
  preferred_name: "Ada",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
  account_link: null,
  created_at: "",
  updated_at: "",
  permissions: {
    can_update_authoritatively: false,
    can_propose_changes: true,
    can_resolve_proposals: false,
    can_propose_account_link: true,
    can_manage_account_link: false,
    can_propose_relationships: true,
    can_manage_relationships: false,
  },
};
const other: Person = {
  ...person,
  id: "01K30000000000000000000001",
  preferred_name: "Beth",
};

function renderPanel(value = person) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PersonRelationshipsPanel
        familySlug="oliver-family"
        person={value}
        people={[value, other]}
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getRelationships).mockResolvedValue([]);
  vi.mocked(getRelationshipProposals).mockResolvedValue([]);
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PersonRelationshipsPanel", () => {
  it("routes a Member contribution through a proposal", async () => {
    const user = userEvent.setup();
    vi.mocked(proposeRelationship).mockResolvedValue({
      id: "p",
      action: "create",
      relationship_id: null,
      subject_person_id: person.id,
      related_person_id: other.id,
      type: "parent_of",
      context: null,
      status: "pending",
      created_at: "",
    });
    renderPanel();
    await user.selectOptions(screen.getByLabelText("Person"), other.id);
    await user.click(
      screen.getByRole("button", { name: "Submit relationship proposal" }),
    );
    await waitFor(() => {
      expect(proposeRelationship).toHaveBeenCalledWith(
        "oliver-family",
        person.id,
        expect.objectContaining({
          action: "create",
          related_person_id: other.id,
        }),
      );
    });
    expect(createRelationship).not.toHaveBeenCalled();
  });

  it("routes an Owner or Administrator through authoritative creation", async () => {
    const user = userEvent.setup();
    const manager = {
      ...person,
      permissions: { ...person.permissions, can_manage_relationships: true },
    };
    vi.mocked(createRelationship).mockResolvedValue({
      id: "r",
      subject_person_id: person.id,
      related_person_id: other.id,
      type: "parent_of",
      status: "confirmed",
      label: "parent",
      other_person: { id: other.id, preferred_name: other.preferred_name },
      context: null,
    });
    renderPanel(manager);
    await user.selectOptions(screen.getByLabelText("Person"), other.id);
    await user.click(screen.getByRole("button", { name: "Add relationship" }));
    await waitFor(() => {
      expect(createRelationship).toHaveBeenCalled();
    });
    expect(proposeRelationship).not.toHaveBeenCalled();
  });
});
