import { useQuery } from "@tanstack/react-query";

import {
  getAccountLinkClaims,
  getFamilyMemberships,
} from "../api/accountLinkApi";
import { personKeys } from "../api/personKeys";

export function useAccountLinkClaimsQuery(
  familySlug: string,
  personId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.accountClaims(familySlug, personId),
    queryFn: ({ signal }) => getAccountLinkClaims(familySlug, personId, signal),
    enabled,
    retry: false,
  });
}

export function useFamilyMembershipsQuery(
  familySlug: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: personKeys.memberships(familySlug),
    queryFn: ({ signal }) => getFamilyMemberships(familySlug, signal),
    enabled,
    retry: false,
    staleTime: 30_000,
  });
}
