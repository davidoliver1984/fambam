import { useInfiniteQuery } from "@tanstack/react-query";

import { searchArchive } from "../api/searchApi";
import { searchKeys } from "../api/searchKeys";
import type { SearchCriteria, SearchGroup } from "../types/search";

export function useArchiveSearchQuery<Group extends SearchGroup>(
  familySlug: string,
  group: Group,
  criteria: SearchCriteria | null,
) {
  return useInfiniteQuery({
    queryKey: searchKeys.group(familySlug, group, criteria ?? {}),
    queryFn: ({ pageParam, signal }) =>
      searchArchive(familySlug, group, criteria ?? {}, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    enabled: familySlug !== "" && criteria !== null,
    retry: false,
  });
}
