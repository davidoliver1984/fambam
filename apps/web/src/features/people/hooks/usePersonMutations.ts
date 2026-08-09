import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  createPerson,
  proposePersonDetails,
  resolvePersonProposal,
  updatePerson,
} from "../api/personApi";
import { personKeys } from "../api/personKeys";
import type { PersonDetailsInput } from "../types/person";
import type { PersonProposalResolution } from "../types/person";

export function useCreatePersonMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PersonDetailsInput) => createPerson(familySlug, input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: personKeys.list(familySlug),
      });
    },
  });
}

export function useUpdatePersonMutation(familySlug: string, personId: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: PersonDetailsInput) =>
      updatePerson(familySlug, personId, input),
    onSuccess: async (person) => {
      queryClient.setQueryData(personKeys.detail(familySlug, personId), person);
      await queryClient.invalidateQueries({
        queryKey: personKeys.list(familySlug),
      });
    },
  });
}

export function useProposePersonDetailsMutation(
  familySlug: string,
  personId: string,
) {
  return useMutation({
    mutationFn: (input: PersonDetailsInput) =>
      proposePersonDetails(familySlug, personId, input),
  });
}

export function useResolvePersonProposalMutation(
  familySlug: string,
  personId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: ({
      proposalId,
      resolution,
    }: {
      proposalId: string;
      resolution: PersonProposalResolution;
    }) => resolvePersonProposal(familySlug, personId, proposalId, resolution),
    onSuccess: async (_proposal, variables) => {
      const invalidations = [
        queryClient.invalidateQueries({
          queryKey: personKeys.proposals(familySlug, personId),
        }),
      ];
      if (variables.resolution === "approve") {
        invalidations.push(
          queryClient.invalidateQueries({
            queryKey: personKeys.detail(familySlug, personId),
          }),
          queryClient.invalidateQueries({
            queryKey: personKeys.list(familySlug),
          }),
        );
      }
      await Promise.all(invalidations);
    },
  });
}
