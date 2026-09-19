import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getFamilySpaceMemberships,
  removeFamilySpaceMembership,
  updateFamilySpaceMembership,
} from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";
import type { FamilySpaceRole } from "../types/familySpace";

export function useFamilySpaceMembershipsQuery(
  familySlug: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: familySpaceKeys.memberships(familySlug),
    queryFn: ({ signal }) => getFamilySpaceMemberships(familySlug, signal),
    enabled,
    retry: false,
  });
}

export function useFamilySpaceMembershipMutations(familySlug: string) {
  const client = useQueryClient();
  const refresh = () =>
    client.invalidateQueries({
      queryKey: familySpaceKeys.memberships(familySlug),
    });
  return {
    update: useMutation({
      mutationFn: ({
        membershipId,
        role,
      }: {
        membershipId: string;
        role: FamilySpaceRole;
      }) => updateFamilySpaceMembership(familySlug, membershipId, role),
      onSuccess: refresh,
    }),
    remove: useMutation({
      mutationFn: (membershipId: string) =>
        removeFamilySpaceMembership(familySlug, membershipId),
      onSuccess: refresh,
    }),
  };
}
