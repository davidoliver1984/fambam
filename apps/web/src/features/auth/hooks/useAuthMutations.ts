import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  login,
  logout,
  requestPasswordReset,
  resetPassword,
  type LoginInput,
  type ResetPasswordInput,
} from "../api/authApi";

export function useLoginMutation() {
  return useMutation({ mutationFn: (input: LoginInput) => login(input) });
}

export function useRequestPasswordResetMutation() {
  return useMutation({ mutationFn: requestPasswordReset });
}

export function useResetPasswordMutation() {
  return useMutation({
    mutationFn: (input: ResetPasswordInput) => resetPassword(input),
  });
}

export function useLogoutMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: logout,
    onSuccess: () => {
      queryClient.clear();
    },
  });
}
