import type { SearchCriteria, SearchGroup } from "../types/search";

export const searchKeys = {
  group: (familySlug: string, group: SearchGroup, criteria: SearchCriteria) =>
    ["families", familySlug, "search", group, criteria] as const,
};
