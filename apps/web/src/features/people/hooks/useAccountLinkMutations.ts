import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  assignAccountLink,
  proposeAccountLink,
  removeAccountLink,
  resolveAccountLinkClaim,
} from "../api/accountLinkApi";
import { personKeys } from "../api/personKeys";

function useRefreshAccountLink(familySlug: string, personId: string) {
  const queryClient = useQueryClient();
  return async () => {
    await Promise.all([
      queryClient.invalidateQueries({
        queryKey: personKeys.detail(familySlug, personId),
      }),
      queryClient.invalidateQueries({ queryKey: personKeys.list(familySlug) }),
    ]);
  };
}

export function useProposeAccountLinkMutation(
  familySlug: string,
  personId: string,
) {
  return useMutation({
    mutationFn: () => proposeAccountLink(familySlug, personId),
  });
}

export function useResolveAccountLinkClaimMutation(
  familySlug: string,
  personId: string,
) {
  const refresh = useRefreshAccountLink(familySlug, personId);
  return useMutation({
    mutationFn: ({
      claimId,
      resolution,
    }: {
      claimId: string;
      resolution: "approve" | "reject";
    }) => resolveAccountLinkClaim(familySlug, personId, claimId, resolution),
    onSuccess: refresh,
  });
}

export function useAssignAccountLinkMutation(
  familySlug: string,
  personId: string,
) {
  const refresh = useRefreshAccountLink(familySlug, personId);
  return useMutation({
    mutationFn: (membershipId: string) =>
      assignAccountLink(familySlug, personId, membershipId),
    onSuccess: refresh,
  });
}

export function useRemoveAccountLinkMutation(
  familySlug: string,
  personId: string,
) {
  const refresh = useRefreshAccountLink(familySlug, personId);
  return useMutation({
    mutationFn: () => removeAccountLink(familySlug, personId),
    onSuccess: refresh,
  });
}
