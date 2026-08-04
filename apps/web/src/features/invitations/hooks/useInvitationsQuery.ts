import { useQuery } from "@tanstack/react-query";

import { getInvitations } from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";

export function useInvitationsQuery(familySpaceId: string) {
  return useQuery({
    queryKey: invitationKeys.list(familySpaceId),
    queryFn: ({ signal }) => getInvitations(familySpaceId, signal),
    staleTime: 30_000,
  });
}
