import { useQuery } from "@tanstack/react-query";

import { getInvitations } from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";

export function useInvitationsQuery(familySlug: string) {
  return useQuery({
    queryKey: invitationKeys.list(familySlug),
    queryFn: ({ signal }) => getInvitations(familySlug, signal),
    staleTime: 30_000,
  });
}
