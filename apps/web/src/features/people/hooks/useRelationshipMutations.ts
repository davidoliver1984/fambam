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
  return async (...additionalPersonIds: string[]) => {
    const personIds = [
      ...new Set([personId, ...additionalPersonIds].filter((id) => id !== "")),
    ];
    await Promise.all(
      personIds.map((id) =>
        queryClient.invalidateQueries({
          queryKey: personKeys.relationships(familySlug, id),
        }),
      ),
    );
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
    onSuccess: async (relationship) =>
      invalidate(
        relationship.subject_person_id,
        relationship.related_person_id,
      ),
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
    mutationFn: (variables: {
      relationshipId: string;
      input: RelationshipInput;
      previousRelatedPersonId: string;
    }) =>
      replaceRelationship(
        familySlug,
        variables.relationshipId,
        personId,
        variables.input,
      ),
    onSuccess: async (relationship, variables) =>
      invalidate(
        variables.previousRelatedPersonId,
        relationship.subject_person_id,
        relationship.related_person_id,
      ),
  });
}

export function useRelationshipActionMutation(
  familySlug: string,
  personId: string,
) {
  const invalidate = useInvalidateRelationships(familySlug, personId);
  return useMutation({
    mutationFn: (variables: {
      relationshipId: string;
      action: "remove" | "dispute";
      relatedPersonId: string;
    }) =>
      variables.action === "remove"
        ? removeRelationship(familySlug, variables.relationshipId)
        : disputeRelationship(familySlug, variables.relationshipId).then(
            () => undefined,
          ),
    onSuccess: async (_result, variables) =>
      invalidate(variables.relatedPersonId),
  });
}

export function useResolveRelationshipProposalMutation(
  familySlug: string,
  personId: string,
) {
  const queryClient = useQueryClient();
  const invalidate = useInvalidateRelationships(familySlug, personId);
  return useMutation({
    mutationFn: ({
      proposalId,
      resolution,
    }: {
      proposalId: string;
      resolution: "approve" | "reject";
    }) =>
      resolveRelationshipProposal(familySlug, personId, proposalId, resolution),
    onSuccess: async (proposal) => {
      await Promise.all([
        invalidate(proposal.subject_person_id, proposal.related_person_id),
        queryClient.invalidateQueries({
          queryKey: personKeys.relationshipProposals(familySlug, personId),
        }),
      ]);
    },
  });
}
