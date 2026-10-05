import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  leaveFamilySpace,
  transferFamilySpaceOwnership,
  updateFamilySpace,
} from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";
import type { UpdateFamilySpaceInput } from "../types/familySpace";

export function useFamilySettingsMutations(familySlug: string) {
  const client = useQueryClient();
  const refreshFamily = async () => {
    await client.invalidateQueries({
      queryKey: familySpaceKeys.detail(familySlug),
    });
    await client.invalidateQueries({ queryKey: familySpaceKeys.list() });
  };
  const refreshFamilyAndMembers = async () => {
    await refreshFamily();
    await client.invalidateQueries({
      queryKey: familySpaceKeys.memberships(familySlug),
    });
  };

  return {
    update: useMutation({
      mutationFn: (input: UpdateFamilySpaceInput) =>
        updateFamilySpace(familySlug, input),
      onSuccess: refreshFamily,
    }),
    transferOwnership: useMutation({
      mutationFn: (membershipId: string) =>
        transferFamilySpaceOwnership(familySlug, membershipId),
      onSuccess: refreshFamilyAndMembers,
    }),
    leave: useMutation({
      mutationFn: () => leaveFamilySpace(familySlug),
      onSuccess: async () => {
        await client.invalidateQueries({ queryKey: familySpaceKeys.all });
      },
    }),
  };
}
