import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  getRecentSignIns,
  removeAccountAvatar,
  requestEmailChange,
  setAccountAvatarWhenReady,
  uploadAccountAvatar,
} from "../api/accountApi";
import { accountKeys } from "../api/accountKeys";

const recentSignInsKey = [...accountKeys.current, "recent-sign-ins"] as const;

export function useRecentSignInsQuery() {
  return useQuery({
    queryKey: recentSignInsKey,
    queryFn: ({ signal }) => getRecentSignIns(signal),
  });
}

export function useRequestEmailChangeMutation() {
  const client = useQueryClient();
  return useMutation({
    mutationFn: requestEmailChange,
    onSuccess: () =>
      client.invalidateQueries({ queryKey: accountKeys.current }),
  });
}

export function useAccountAvatarMutations(familySlug: string) {
  const client = useQueryClient();
  const refresh = () =>
    client.invalidateQueries({ queryKey: accountKeys.current });

  return {
    upload: useMutation({
      mutationFn: async (file: File) => {
        const id = await uploadAccountAvatar(
          familySlug,
          file,
          crypto.randomUUID(),
        );
        await setAccountAvatarWhenReady(id);
      },
      onSuccess: refresh,
    }),
    remove: useMutation({
      mutationFn: removeAccountAvatar,
      onSuccess: refresh,
    }),
  };
}
