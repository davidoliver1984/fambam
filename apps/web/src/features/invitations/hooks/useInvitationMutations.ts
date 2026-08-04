import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  acceptInvitation,
  issueInvitation,
  transitionInvitation,
} from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";
import type {
  AcceptInvitationInput,
  InvitationTransition,
} from "../types/invitation";

export function useIssueInvitationMutation(familySpaceId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: issueInvitation,
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: invitationKeys.list(familySpaceId),
      });
    },
  });
}

export function useTransitionInvitationMutation(familySpaceId: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      invitationId,
      action,
    }: {
      invitationId: number;
      action: InvitationTransition;
    }) => transitionInvitation(invitationId, action),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: invitationKeys.list(familySpaceId),
      });
    },
  });
}

export function useAcceptInvitationMutation() {
  return useMutation({
    mutationFn: (input: AcceptInvitationInput) => acceptInvitation(input),
  });
}
