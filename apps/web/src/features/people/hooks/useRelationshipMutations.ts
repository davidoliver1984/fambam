import { useMutation, useQueryClient } from "@tanstack/react-query";

import { personKeys } from "../api/personKeys";
import {
  createRelationship,
  disputeRelationship,
  proposeRelationship,
  removeRelationship,
  replaceRelationship,
  resolveRelationshipProposal,
} from "../api/relationshipApi";
import type {
  RelationshipInput,
  RelationshipProposalInput,
} from "../types/person";

function useInvalidateRelationships(familySlug: string, personId: string) {
  const queryClient = useQueryClient();
  return async () => {
    await queryClient.invalidateQueries({
      queryKey: personKeys.relationships(familySlug, personId),
    });
  };
}

export function useCreateRelationshipMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateRelationships(familySlug, personId);
  return useMutation({
    mutationFn: (input: RelationshipInput) =>
      createRelationship(familySlug, personId, input),
    onSuccess: invalidate,
  });
}

export function useProposeRelationshipMutation(
  familySlug: string,
  personId: string,
) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: RelationshipProposalInput) =>
      proposeRelationship(familySlug, personId, input),
    onSuccess: async () =>
      queryClient.invalidateQueries({
        queryKey: personKeys.relationshipProposals(familySlug, personId),
      }),
  });
}

export function useReplaceRelationshipMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateRelationships(familySlug, personId);
  return useMutation({
    mutationFn: ({
      relationshipId,
      input,
    }: {
      relationshipId: string;
      input: RelationshipInput;
    }) => replaceRelationship(familySlug, relationshipId, personId, input),
    onSuccess: invalidate,
  });
}

export function useRelationshipActionMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateRelationships(familySlug, personId);
  return useMutation({
    mutationFn: ({
      relationshipId,
      action,
    }: {
      relationshipId: string;
      action: "remove" | "dispute";
    }) =>
      action === "remove"
        ? removeRelationship(familySlug, relationshipId)
        : disputeRelationship(familySlug, relationshipId).then(() => undefined),
    onSuccess: invalidate,
  });
}

export function useResolveRelationshipProposalMutation(
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
      resolution: "approve" | "reject";
    }) =>
      resolveRelationshipProposal(familySlug, personId, proposalId, resolution),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: personKeys.relationships(familySlug, personId),
        }),
        queryClient.invalidateQueries({
          queryKey: personKeys.relationshipProposals(familySlug, personId),
        }),
      ]);
    },
  });
}
