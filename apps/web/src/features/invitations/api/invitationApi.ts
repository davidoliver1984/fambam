import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type {
  AcceptInvitationInput,
  AcceptanceClaim,
  AcceptedAccount,
  Invitation,
  InvitationTransition,
} from "../types/invitation";

export async function getInvitations(
  signal?: AbortSignal,
): Promise<Invitation[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Invitation[]>>("/api/invitations", {
      signal,
    }),
  );
}

export async function issueInvitation(email: string): Promise<Invitation> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<Invitation>>("/api/invitations", {
      email,
    }),
  );
}

export async function transitionInvitation(
  invitationId: number,
  action: InvitationTransition,
): Promise<Invitation> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<Invitation>>(
      `/api/invitations/${String(invitationId)}/${action}`,
    ),
  );
}

export async function exchangeInvitationToken(
  token: string,
): Promise<AcceptanceClaim> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<AcceptanceClaim>>(
      "/api/invitations/exchange",
      { token },
    ),
  );
}

export async function acceptInvitation(
  input: AcceptInvitationInput,
): Promise<AcceptedAccount> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<AcceptedAccount>>(
      "/api/invitations/accept",
      input,
    ),
  );
}
