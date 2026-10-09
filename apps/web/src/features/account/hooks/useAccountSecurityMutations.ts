import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  revokeSessions,
  updatePassword,
  type UpdatePasswordInput,
} from "../api/accountSecurityApi";

export function useUpdatePasswordMutation() {
  return useMutation({
    mutationFn: (input: UpdatePasswordInput) => updatePassword(input),
  });
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
