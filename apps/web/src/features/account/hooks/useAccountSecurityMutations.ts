import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  revokeSessions,
  updatePassword,
  type UpdatePasswordInput,
} from "../api/accountSecurityApi";

function useRevokingMutation<T>(mutationFn: (input: T) => Promise<void>) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn,
    onSuccess: () => {
      queryClient.clear();
    },
  });
}

export function useUpdatePasswordMutation() {
  return useRevokingMutation((input: UpdatePasswordInput) =>
    updatePassword(input),
  );
}

export function useRevokeSessionsMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: revokeSessions,
    onSuccess: () => {
      queryClient.clear();
    },
  });
}
