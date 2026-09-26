import type {
  SearchCriteria,
  SearchGroup,
  SearchSuggestionType,
} from "../types/search";

export const searchKeys = {
  all: (familySlug: string) => ["families", familySlug, "search"] as const,
  group: (familySlug: string, group: SearchGroup, criteria: SearchCriteria) =>
    [...searchKeys.all(familySlug), group, criteria] as const,
  suggestions: (
    familySlug: string,
    type: SearchSuggestionType,
    prefix: string,
  ) => [...searchKeys.all(familySlug), "suggestions", type, prefix] as const,
  discovery: (familySlug: string, type: string, id: string) =>
    ["families", familySlug, "discovery", type, id] as const,
  saved: (familySlug: string) =>
    ["families", familySlug, "saved-searches"] as const,
  savedResults: (familySlug: string, id: string, group: SearchGroup) =>
    ["families", familySlug, "saved-searches", id, group] as const,
};
