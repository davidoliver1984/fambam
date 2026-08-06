import { useQuery } from "@tanstack/react-query";

import { getPeople } from "../api/personApi";
import { personKeys } from "../api/personKeys";

export function usePeopleQuery(familySlug: string) {
  return useQuery({
    queryKey: personKeys.list(familySlug),
    queryFn: ({ signal }) => getPeople(familySlug, signal),
    retry: false,
    staleTime: 30_000,
  });
}
