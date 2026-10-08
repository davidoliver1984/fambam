import { useInfiniteQuery } from "@tanstack/react-query";

import { getPeople } from "../api/personApi";
import { personKeys } from "../api/personKeys";
import type { PersonListCriteria } from "../types/person";

export function usePeopleQuery(
  familySlug: string,
  criteriaOrEnabled: PersonListCriteria | boolean = {},
  enabled = true,
) {
  const criteria =
    typeof criteriaOrEnabled === "boolean" ? {} : criteriaOrEnabled;
  const queryEnabled =
    typeof criteriaOrEnabled === "boolean" ? criteriaOrEnabled : enabled;
  return useInfiniteQuery({
    queryKey: personKeys.page(familySlug, criteria),
    queryFn: ({ pageParam, signal }) =>
      getPeople(familySlug, criteria, pageParam, signal),
    initialPageParam: null as string | null,
    getNextPageParam: (lastPage) => lastPage.next_cursor ?? undefined,
    select: (data) => data.pages.flatMap((page) => page.items),
    retry: false,
    staleTime: 30_000,
    enabled: queryEnabled && familySlug !== "",
  });
}
