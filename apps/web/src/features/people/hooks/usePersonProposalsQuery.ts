import { useQuery } from "@tanstack/react-query";

import { getPersonProposals } from "../api/personApi";
import { personKeys } from "../api/personKeys";

export function usePersonProposalsQuery(
  familySlug: string,
  personId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.proposals(familySlug, personId),
    queryFn: ({ signal }) => getPersonProposals(familySlug, personId, signal),
    enabled,
    retry: false,
  });
}
