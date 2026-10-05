import { useQuery } from "@tanstack/react-query";

import { getSettingsOverview } from "../api/settingsOverviewApi";
import { familySpaceKeys } from "../api/familySpaceKeys";

export function useSettingsOverviewQuery(familySlug: string) {
  return useQuery({
    queryKey: familySpaceKeys.settingsOverview(familySlug),
    queryFn: ({ signal }) => getSettingsOverview(familySlug, signal),
    enabled: familySlug !== "",
  });
}
