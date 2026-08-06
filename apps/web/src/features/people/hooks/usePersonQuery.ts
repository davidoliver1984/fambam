import { useQuery } from "@tanstack/react-query";

import { getPerson } from "../api/personApi";
import { personKeys } from "../api/personKeys";

export function usePersonQuery(familySlug: string, personId: string) {
  return useQuery({
    queryKey: personKeys.detail(familySlug, personId),
    queryFn: ({ signal }) => getPerson(familySlug, personId, signal),
    retry: false,
    staleTime: 30_000,
  });
}
