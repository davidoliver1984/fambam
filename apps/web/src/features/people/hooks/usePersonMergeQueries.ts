import { useQuery } from "@tanstack/react-query";

import { getPersonMergeProposals, getPersonMerges } from "../api/mergeApi";
import { personKeys } from "../api/personKeys";

export function usePersonMergesQuery(
  familySlug: string,
  personId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.merges(familySlug, personId),
    queryFn: ({ signal }) => getPersonMerges(familySlug, personId, signal),
    enabled: enabled && familySlug !== "" && personId !== "",
  });
}

export function usePersonMergeProposalsQuery(
  familySlug: string,
  personId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.mergeProposals(familySlug, personId),
    queryFn: ({ signal }) =>
      getPersonMergeProposals(familySlug, personId, signal),
    enabled: enabled && familySlug !== "" && personId !== "",
  });
}
