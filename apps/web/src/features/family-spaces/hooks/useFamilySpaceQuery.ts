import { useQuery } from "@tanstack/react-query";

import { getFamilySpace } from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";

export function useFamilySpaceQuery(familySlug: string) {
  return useQuery({
    queryKey: familySpaceKeys.detail(familySlug),
    queryFn: ({ signal }) => getFamilySpace(familySlug, signal),
    retry: false,
    staleTime: 30_000,
  });
}
