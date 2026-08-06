import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  mergePerson,
  proposePersonMerge,
  resolvePersonMergeProposal,
  reversePersonMerge,
} from "../api/mergeApi";
import { personKeys } from "../api/personKeys";
import type {
  AccountLinkResolution,
  PersonMergeInput,
  PersonMergeProposalInput,
} from "../types/person";

function useInvalidateMergeState(familySlug: string, personId: string) {
  const queryClient = useQueryClient();
  return async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: personKeys.list(familySlug) }),
      queryClient.invalidateQueries({ queryKey: personKeys.details() }),
      queryClient.invalidateQueries({
        queryKey: personKeys.merges(familySlug, personId),
      }),
    ]);
  };
}

export function useMergePersonMutation(familySlug: string, personId: string) {
  const invalidate = useInvalidateMergeState(familySlug, personId);
  return useMutation({
    mutationFn: (input: PersonMergeInput) =>
      mergePerson(familySlug, personId, input),
    onSuccess: invalidate,
  });
}

export function useProposePersonMergeMutation(
  familySlug: string,
  personId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PersonMergeProposalInput) =>
      proposePersonMerge(familySlug, personId, input),
    onSuccess: async () =>
      queryClient.invalidateQueries({
        queryKey: personKeys.mergeProposals(familySlug, personId),
      }),
  });
}

export function useResolvePersonMergeProposalMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateMergeState(familySlug, personId);
  return useMutation({
    mutationFn: ({
      proposalId,
      resolution,
      accountLinkResolution,
    }: {
      proposalId: string;
      resolution: "approve" | "reject";
      accountLinkResolution?: AccountLinkResolution;
    }) =>
      resolvePersonMergeProposal(
        familySlug,
        personId,
        proposalId,
        resolution,
        accountLinkResolution,
      ),
    onSuccess: invalidate,
  });
}

export function useReversePersonMergeMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateMergeState(familySlug, personId);
  return useMutation({
    mutationFn: (mergeId: string) => reversePersonMerge(familySlug, mergeId),
    onSuccess: invalidate,
  });
}
