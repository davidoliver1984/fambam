import { useQuery } from "@tanstack/react-query";

import { exchangeInvitationToken } from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";

export function useInvitationClaimQuery(token: string | null) {
  return useQuery({
    queryKey: invitationKeys.claim(),
    queryFn: () => exchangeInvitationToken(token ?? ""),
    enabled: token !== null,
    retry: false,
    staleTime: Number.POSITIVE_INFINITY,
    refetchOnMount: false,
    refetchOnReconnect: false,
    refetchOnWindowFocus: false,
  });
}
