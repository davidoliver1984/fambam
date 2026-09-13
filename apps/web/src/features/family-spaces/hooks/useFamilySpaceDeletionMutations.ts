import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  cancelFamilySpaceDeletion,
  requestFamilySpaceDeletion,
} from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";

export function useFamilySpaceDeletionMutations(familySlug: string) {
  const queryClient = useQueryClient();
  const update = () =>
    queryClient.invalidateQueries({
      queryKey: familySpaceKeys.detail(familySlug),
    });

  return {
    request: useMutation({
      mutationFn: () => requestFamilySpaceDeletion(familySlug),
      onSuccess: update,
    }),
    cancel: useMutation({
      mutationFn: () => cancelFamilySpaceDeletion(familySlug),
      onSuccess: update,
    }),
  };
}
