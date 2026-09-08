import { useInfiniteQuery, useQuery } from "@tanstack/react-query";

import {
  getDiscovery,
  getSearchSuggestions,
  searchArchive,
} from "../api/searchApi";
import { searchKeys } from "../api/searchKeys";
import type {
  DiscoveryResponse,
  SearchCriteria,
  SearchGroup,
  SearchSuggestionType,
} from "../types/search";

export function useArchiveSearchQuery<Group extends SearchGroup>(
  familySlug: string,
  group: Group,
  criteria: SearchCriteria | null,
  enabled = true,
) {
  return useInfiniteQuery({
    queryKey: searchKeys.group(familySlug, group, criteria ?? {}),
    queryFn: ({ pageParam, signal }) =>
      searchArchive(familySlug, group, criteria ?? {}, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    enabled: enabled && familySlug !== "" && criteria !== null,
    retry: false,
  });
}

export function useSearchSuggestionsQuery(
  familySlug: string,
  type: SearchSuggestionType,
  prefix: string,
  enabled = true,
) {
  return useQuery({
    queryKey: searchKeys.suggestions(familySlug, type, prefix),
    queryFn: ({ signal }) =>
      getSearchSuggestions(familySlug, type, prefix, signal),
    enabled: enabled && familySlug !== "" && prefix.trim() !== "",
    retry: false,
  });
}

export function useDiscoveryQuery(
  familySlug: string,
  type: DiscoveryResponse["source"]["type"],
  id: string,
) {
  return useQuery({
    queryKey: searchKeys.discovery(familySlug, type, id),
    queryFn: ({ signal }) => getDiscovery(familySlug, type, id, signal),
    enabled: familySlug !== "" && id !== "",
    retry: false,
  });
}
