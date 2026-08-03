import { useQuery } from "@tanstack/react-query";

import { getCurrentUser } from "../api/accountApi";
import { accountKeys } from "../api/accountKeys";

export function useCurrentUserQuery() {
  return useQuery({
    queryKey: accountKeys.current,
    queryFn: ({ signal }) => getCurrentUser(signal),
    retry: false,
    staleTime: 30_000,
  });
}
