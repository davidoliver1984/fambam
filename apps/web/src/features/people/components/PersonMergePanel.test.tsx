import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  getPersonMergeProposals,
  getPersonMerges,
  mergePerson,
  proposePersonMerge,
  resolvePersonMergeProposal,
  reversePersonMerge,
} from "../api/mergeApi";
import type { Person } from "../types/person";
import { PersonMergePanel } from "./PersonMergePanel";

vi.mock("../api/mergeApi", () => ({
  getPersonMergeProposals: vi.fn(),
  getPersonMerges: vi.fn(),
  mergePerson: vi.fn(),
  proposePersonMerge: vi.fn(),
  resolvePersonMergeProposal: vi.fn(),
  reversePersonMerge: vi.fn(),
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
  redirected_from_person_id: null,
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
    can_propose_merge: true,
    can_manage_merge: false,
  },
};
const survivor: Person = {
  ...person,
  id: "01K30000000000000000000001",
  preferred_name: "Beth",
};
const proposal = {
  id: "proposal",
  survivor: { id: survivor.id, preferred_name: survivor.preferred_name },
  absorbed: { id: person.id, preferred_name: person.preferred_name },
  context: "Same record",
  status: "pending" as const,
  person_merge_id: null,
  created_at: "",
};
const merge = {
  id: "merge",
  survivor: proposal.survivor,
  absorbed: proposal.absorbed,
  status: "active" as const,
  merged_at: "",
  reversed_at: null,
};

function renderPanel(value = person) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PersonMergePanel
        familySlug="oliver-family"
        person={value}
        people={[value, survivor]}
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPersonMergeProposals).mockResolvedValue([]);
  vi.mocked(getPersonMerges).mockResolvedValue([]);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PersonMergePanel", () => {
  it("routes a Member through a duplicate proposal", async () => {
    const user = userEvent.setup();
    vi.mocked(proposePersonMerge).mockResolvedValue(proposal);
    renderPanel();
    await user.selectOptions(
      screen.getByLabelText("Surviving Person"),
      survivor.id,
    );
    await user.type(
      screen.getByLabelText("Why do these look alike?"),
      "Same record",
    );
    await user.click(
      screen.getByRole("button", { name: "Submit duplicate proposal" }),
    );
    await waitFor(() => {
      expect(proposePersonMerge).toHaveBeenCalledWith(
        "oliver-family",
        person.id,
        {
          survivor_person_id: survivor.id,
          context: "Same record",
        },
      );
    });
    expect(mergePerson).not.toHaveBeenCalled();
  });

  it("gives managers authoritative merge, proposal resolution and reversal actions", async () => {
    const user = userEvent.setup();
    const manager = {
      ...person,
      permissions: { ...person.permissions, can_manage_merge: true },
    };
    vi.mocked(getPersonMergeProposals).mockResolvedValue([proposal]);
    vi.mocked(getPersonMerges).mockResolvedValue([merge]);
    vi.mocked(mergePerson).mockResolvedValue(merge);
    vi.mocked(resolvePersonMergeProposal).mockResolvedValue({
      ...proposal,
      status: "approved",
      person_merge_id: merge.id,
    });
    vi.mocked(reversePersonMerge).mockResolvedValue({
      ...merge,
      status: "reversed",
      reversed_at: "now",
    });
    renderPanel(manager);

    await screen.findByText("Same record");
    await user.selectOptions(
      screen.getByLabelText("Surviving Person"),
      survivor.id,
    );
    await user.click(screen.getByRole("button", { name: "Merge Person" }));
    await user.click(
      screen.getByRole("button", { name: "Approve duplicate merge" }),
    );
    await user.click(screen.getByRole("button", { name: "Reverse merge" }));

    await waitFor(() => {
      expect(mergePerson).toHaveBeenCalled();
      expect(resolvePersonMergeProposal).toHaveBeenCalled();
      expect(reversePersonMerge).toHaveBeenCalledWith(
        "oliver-family",
        merge.id,
      );
    });
  });
});
