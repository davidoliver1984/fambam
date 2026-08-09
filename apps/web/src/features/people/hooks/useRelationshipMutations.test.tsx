import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { describe, expect, it, vi } from "vitest";

import {
  createRelationship,
  removeRelationship,
  replaceRelationship,
  resolveRelationshipProposal,
} from "../api/relationshipApi";
import { personKeys } from "../api/personKeys";
import {
  useCreateRelationshipMutation,
  useRelationshipActionMutation,
  useReplaceRelationshipMutation,
  useResolveRelationshipProposalMutation,
} from "./useRelationshipMutations";

vi.mock("../api/relationshipApi", () => ({
  createRelationship: vi.fn(),
  disputeRelationship: vi.fn(),
  proposeRelationship: vi.fn(),
  removeRelationship: vi.fn(),
  replaceRelationship: vi.fn(),
  resolveRelationshipProposal: vi.fn(),
}));

const familySlug = "family";
const personId = "01K30000000000000000000000";
const otherId = "01K30000000000000000000001";
const newOtherId = "01K30000000000000000000002";
const relationship = {
  id: "relationship",
  subject_person_id: personId,
  related_person_id: otherId,
  type: "parent_of" as const,
  status: "confirmed" as const,
  label: "parent",
  other_person: { id: otherId, preferred_name: "Beth" },
  context: null,
};

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

function seed(client: QueryClient, id: string) {
  client.setQueryData(personKeys.relationships(familySlug, id), []);
}

function expectInvalidated(client: QueryClient, id: string) {
  expect(
    client.getQueryState(personKeys.relationships(familySlug, id))
      ?.isInvalidated,
  ).toBe(true);
}

describe("relationship mutation invalidation", () => {
  it("invalidates both People after relationship creation", async () => {
    const { client, wrapper } = setup();
    seed(client, otherId);
    vi.mocked(createRelationship).mockResolvedValue(relationship);
    const { result } = renderHook(
      () => useCreateRelationshipMutation(familySlug, personId),
      { wrapper },
    );

    await result.current.mutateAsync({
      related_person_id: otherId,
      type: "parent_of",
    });

    expectInvalidated(client, otherId);
  });

  it("invalidates the old and new other Person after replacement", async () => {
    const { client, wrapper } = setup();
    seed(client, otherId);
    seed(client, newOtherId);
    vi.mocked(replaceRelationship).mockResolvedValue({
      ...relationship,
      related_person_id: newOtherId,
      other_person: { id: newOtherId, preferred_name: "Cara" },
    });
    const { result } = renderHook(
      () => useReplaceRelationshipMutation(familySlug, personId),
      { wrapper },
    );

    await result.current.mutateAsync({
      relationshipId: relationship.id,
      previousRelatedPersonId: otherId,
      input: { related_person_id: newOtherId, type: "parent_of" },
    });

    expectInvalidated(client, otherId);
    expectInvalidated(client, newOtherId);
  });

  it("invalidates the other Person after removal", async () => {
    const { client, wrapper } = setup();
    seed(client, otherId);
    vi.mocked(removeRelationship).mockResolvedValue(undefined);
    const { result } = renderHook(
      () => useRelationshipActionMutation(familySlug, personId),
      { wrapper },
    );

    await result.current.mutateAsync({
      relationshipId: relationship.id,
      action: "remove",
      relatedPersonId: otherId,
    });

    expectInvalidated(client, otherId);
  });

  it("invalidates both proposal People after resolution", async () => {
    const { client, wrapper } = setup();
    seed(client, otherId);
    vi.mocked(resolveRelationshipProposal).mockResolvedValue({
      id: "proposal",
      action: "create",
      relationship_id: null,
      subject_person_id: personId,
      related_person_id: otherId,
      type: "parent_of",
      context: null,
      status: "approved",
      created_at: "",
    });
    const { result } = renderHook(
      () => useResolveRelationshipProposalMutation(familySlug, personId),
      { wrapper },
    );

    await result.current.mutateAsync({
      proposalId: "proposal",
      resolution: "approve",
    });

    expectInvalidated(client, otherId);
  });
});
