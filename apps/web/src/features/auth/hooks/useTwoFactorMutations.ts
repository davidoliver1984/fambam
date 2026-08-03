import { useMutation, useQueryClient } from "@tanstack/react-query";

import { accountKeys } from "@/features/account/api/accountKeys";

import {
  completeTwoFactorChallenge,
  confirmPassword,
  confirmTwoFactor,
  disableTwoFactor,
  enableTwoFactor,
  regenerateRecoveryCodes,
  type TwoFactorChallengeInput,
} from "../api/twoFactorApi";
import { twoFactorKeys } from "../api/twoFactorKeys";

export function useBeginTwoFactorSetupMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (password: string) => {
      await confirmPassword(password);
      await enableTwoFactor();
      return regenerateRecoveryCodes();
    },
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: twoFactorKeys.setup() });
    },
  });
}

export function useConfirmTwoFactorMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: confirmTwoFactor,
    onSuccess: async () => {
      await queryClient.invalidateQueries({ queryKey: accountKeys.current });
    },
  });
}

export function useDisableTwoFactorMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: async (password: string) => {
      await confirmPassword(password);
      await disableTwoFactor();
    },
    onSuccess: async () => {
      queryClient.removeQueries({ queryKey: twoFactorKeys.all });
      await queryClient.invalidateQueries({ queryKey: accountKeys.current });
    },
  });
}

export function useTwoFactorChallengeMutation() {
  return useMutation({
    mutationFn: (input: TwoFactorChallengeInput) =>
      completeTwoFactorChallenge(input),
  });
}
