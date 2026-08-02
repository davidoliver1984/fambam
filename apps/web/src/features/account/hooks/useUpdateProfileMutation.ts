import { useMutation, useQueryClient } from "@tanstack/react-query";

import { updateProfile } from "../api/accountApi";
import { accountKeys } from "../api/accountKeys";
import type { UpdateProfileInput } from "../types/user";

export function useUpdateProfileMutation() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: UpdateProfileInput) => updateProfile(input),
    onSuccess: (user) => {
      queryClient.setQueryData(accountKeys.current, user);
    },
  });
}
