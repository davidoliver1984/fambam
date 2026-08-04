import { useQuery } from "@tanstack/react-query";

import { getFamilySpaces } from "../api/familySpaceApi";
import { familySpaceKeys } from "../api/familySpaceKeys";

export function useFamilySpacesQuery() {
  return useQuery({
    queryKey: familySpaceKeys.list(),
    queryFn: ({ signal }) => getFamilySpaces(signal),
    staleTime: 30_000,
  });
}
