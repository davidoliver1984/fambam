import type {
  SearchCriteria,
  SearchGroup,
  SearchSuggestionType,
} from "../types/search";

export const searchKeys = {
  group: (familySlug: string, group: SearchGroup, criteria: SearchCriteria) =>
    ["families", familySlug, "search", group, criteria] as const,
  suggestions: (
    familySlug: string,
    type: SearchSuggestionType,
    prefix: string,
  ) => ["families", familySlug, "search", "suggestions", type, prefix] as const,
  discovery: (familySlug: string, type: string, id: string) =>
    ["families", familySlug, "discovery", type, id] as const,
  saved: (familySlug: string) =>
    ["families", familySlug, "saved-searches"] as const,
  savedResults: (familySlug: string, id: string, group: SearchGroup) =>
    ["families", familySlug, "saved-searches", id, group] as const,
};
