import { useQuery } from "@tanstack/react-query";

import { getRecentFamilyActivities } from "@/features/activities/api/familyActivityApi";
import { familyActivityKeys } from "@/features/activities/api/familyActivityKeys";

export function useRecentFamilyActivitiesQuery(familySlug: string) {
  return useQuery({
    queryKey: familyActivityKeys.recent(familySlug),
    queryFn: ({ signal }) => getRecentFamilyActivities(familySlug, signal),
    enabled: familySlug !== "",
    staleTime: 30_000,
  });
}
