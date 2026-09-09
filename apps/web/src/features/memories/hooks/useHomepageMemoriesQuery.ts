import { useQuery } from "@tanstack/react-query";

import { getHomepageMemories } from "@/features/memories/api/memoryApi";
import { memoryKeys } from "@/features/memories/api/memoryKeys";

export function useHomepageMemoriesQuery(familySlug: string) {
  return useQuery({
    queryKey: memoryKeys.homepage(familySlug),
    queryFn: ({ signal }) => getHomepageMemories(familySlug, signal),
    enabled: familySlug !== "",
  });
}
