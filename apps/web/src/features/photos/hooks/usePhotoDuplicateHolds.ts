import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getDuplicateHolds,
  resolveDuplicateHold,
} from "../api/photoDuplicateApi";
import { photoKeys } from "../api/photoKeys";
import type { ResolveDuplicateHoldInput } from "../types/photo";

export function usePhotoDuplicateHoldsQuery(familySlug: string) {
  return useQuery({
    queryKey: photoKeys.duplicateHolds(familySlug),
    queryFn: ({ signal }) => getDuplicateHolds(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}

export function useResolvePhotoDuplicateHoldMutation(familySlug: string) {
  const queryClient = useQueryClient();
  return useMutation({
    mutationFn: (input: ResolveDuplicateHoldInput) =>
      resolveDuplicateHold(familySlug, input),
    onSuccess: async () => {
      await Promise.all([
        queryClient.invalidateQueries({
          queryKey: photoKeys.duplicateHolds(familySlug),
        }),
        queryClient.invalidateQueries({ queryKey: photoKeys.list(familySlug) }),
      ]);
    },
  });
}
