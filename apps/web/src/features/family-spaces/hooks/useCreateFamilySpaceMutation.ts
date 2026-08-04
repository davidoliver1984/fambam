import { useMutation, useQueryClient } from "@tanstack/react-query";

import { createFamilySpace } from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";

export function useCreateFamilySpaceMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: createFamilySpace,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: familySpaceKeys.list() });
    },
  });
}
