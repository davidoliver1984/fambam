import { useQuery } from "@tanstack/react-query";

import {
  getRelationshipProposals,
  getRelationships,
} from "../api/relationshipApi";
import { personKeys } from "../api/personKeys";

export function useRelationshipsQuery(familySlug: string, personId: string) {
  return useQuery({
    queryKey: personKeys.relationships(familySlug, personId),
    queryFn: ({ signal }) => getRelationships(familySlug, personId, signal),
    enabled: familySlug !== "" && personId !== "",
  });
}

export function useRelationshipProposalsQuery(
  familySlug: string,
  personId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.relationshipProposals(familySlug, personId),
    queryFn: ({ signal }) =>
      getRelationshipProposals(familySlug, personId, signal),
    enabled: enabled && familySlug !== "" && personId !== "",
  });
}
