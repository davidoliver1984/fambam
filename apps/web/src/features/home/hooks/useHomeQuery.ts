import { useQuery } from "@tanstack/react-query";

import { getHomeReadModel } from "@/features/home/api/homeApi";

export const homeKeys = {
  all: ["home"] as const,
  detail: (familySlug: string) => [...homeKeys.all, { familySlug }] as const,
};

export function useHomeQuery(familySlug: string) {
  return useQuery({
    queryKey: homeKeys.detail(familySlug),
    queryFn: ({ signal }) => getHomeReadModel(familySlug, signal),
    enabled: familySlug !== "",
  });
}
