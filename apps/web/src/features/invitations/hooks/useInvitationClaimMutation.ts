import { useMutation, useMutationState } from "@tanstack/react-query";
import { useEffect, useRef } from "react";

import { exchangeInvitationToken } from "../api/invitationApi";
import { invitationKeys } from "../api/invitationKeys";
import type { AcceptanceClaim } from "../types/invitation";

function isAcceptanceClaim(value: unknown): value is AcceptanceClaim {
  return (
    typeof value === "object" &&
    value !== null &&
    "claim_token" in value &&
    typeof value.claim_token === "string" &&
    "email" in value &&
    typeof value.email === "string" &&
    "expires_at" in value &&
    typeof value.expires_at === "string"
  );
}

export function useInvitationClaimMutation(token: string | null) {
  const started = useRef(false);
  const mutationKey = invitationKeys.claimExchange();
  const mutation = useMutation({
    mutationKey,
    mutationFn: exchangeInvitationToken,
  });
  const mutationStates = useMutationState({
    filters: { mutationKey, exact: true },
  });
  const latest = mutationStates.at(-1);
  const { mutate } = mutation;

  useEffect(() => {
    if (token === null || started.current) return;

    started.current = true;
    mutate(token);
  }, [mutate, token]);

  return {
    data: isAcceptanceClaim(latest?.data) ? latest.data : undefined,
    isError: latest?.status === "error",
    isPending: latest?.status === "pending",
  };
}
