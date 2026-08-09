import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { expect, it, vi } from "vitest";

import { resolvePersonProposal } from "../api/personApi";
import { personKeys } from "../api/personKeys";
import { useResolvePersonProposalMutation } from "./usePersonMutations";

vi.mock("../api/personApi", () => ({
  createPerson: vi.fn(),
  proposePersonDetails: vi.fn(),
  resolvePersonProposal: vi.fn(),
  updatePerson: vi.fn(),
}));

it("invalidates the Person detail, proposal queue and People list after approval", async () => {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const familySlug = "family";
  const personId = "01K30000000000000000000000";
  const keys = [
    personKeys.detail(familySlug, personId),
    personKeys.proposals(familySlug, personId),
    personKeys.list(familySlug),
  ];
  keys.forEach((key) => {
    client.setQueryData(key, {});
  });
  vi.mocked(resolvePersonProposal).mockResolvedValue({
    id: "proposal",
    person_id: personId,
    changes: { preferred_name: "Updated" },
    status: "approved",
    proposed_by: 2,
    resolved_by: 1,
    resolved_at: "",
    created_at: "",
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  const { result } = renderHook(
    () => useResolvePersonProposalMutation(familySlug, personId),
    { wrapper },
  );

  await result.current.mutateAsync({
    proposalId: "proposal",
    resolution: "approve",
  });

  keys.forEach((key) => {
    expect(client.getQueryState(key)?.isInvalidated).toBe(true);
  });
});
