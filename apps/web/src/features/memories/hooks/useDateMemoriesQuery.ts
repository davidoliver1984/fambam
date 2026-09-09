import { useQuery } from "@tanstack/react-query";

import { getDateMemories } from "@/features/memories/api/memoryApi";
import { memoryKeys } from "@/features/memories/api/memoryKeys";

export function useDateMemoriesQuery(familySlug: string) {
  return useQuery({
    queryKey: memoryKeys.dateBased(familySlug),
    queryFn: ({ signal }) => getDateMemories(familySlug, signal),
    enabled: familySlug !== "",
    staleTime: 60_000,
  });
}
