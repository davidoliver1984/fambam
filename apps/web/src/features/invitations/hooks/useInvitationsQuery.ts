import { useQuery } from "@tanstack/react-query";

import { getInvitations } from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";

export function useInvitationsQuery() {
  return useQuery({
    queryKey: invitationKeys.list(),
    queryFn: getInvitations,
    staleTime: 30_000,
  });
}
