import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { getLoveSummary, removeLove, saveLove } from "../api/loveApi";
import type { LoveTarget } from "../types/love";

export function useLove(
  familySlug: string,
  targetType: LoveTarget,
  targetId: string,
) {
  const key = ["love", familySlug, targetType, targetId] as const;
  const client = useQueryClient();
  const query = useQuery({
    queryKey: key,
    queryFn: ({ signal }) =>
      getLoveSummary(familySlug, targetType, targetId, signal),
    retry: false,
  });
  const mutation = useMutation({
    mutationFn: async (loved: boolean) => {
      if (loved) {
        await removeLove(familySlug, targetType, targetId);
      } else {
        await saveLove(familySlug, targetType, targetId);
      }
    },
    onSuccess: () => client.invalidateQueries({ queryKey: key }),
  });
  return { query, mutation };
}
