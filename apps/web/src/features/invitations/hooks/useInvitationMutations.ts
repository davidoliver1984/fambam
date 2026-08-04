import { useMutation, useQueryClient } from "@tanstack/react-query";

import {
  acceptInvitation,
  issueInvitation,
  transitionInvitation,
} from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";
import type {
  AcceptInvitationInput,
  IssueInvitationInput,
  InvitationTransition,
} from "../types/invitation";

export function useIssueInvitationMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: (input: IssueInvitationInput) =>
      issueInvitation(familySlug, input),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: invitationKeys.list(familySlug),
      });
    },
  });
}

export function useTransitionInvitationMutation(familySlug: string) {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: ({
      invitationId,
      action,
    }: {
      invitationId: number;
      action: InvitationTransition;
    }) => transitionInvitation(familySlug, invitationId, action),
    onSuccess: async () => {
      await queryClient.invalidateQueries({
        queryKey: invitationKeys.list(familySlug),
      });
    },
  });
}

export function useAcceptInvitationMutation() {
  return useMutation({
    mutationFn: (input: AcceptInvitationInput) => acceptInvitation(input),
  });
}
