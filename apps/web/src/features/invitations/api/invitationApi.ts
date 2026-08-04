import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type {
  AcceptInvitationInput,
  AcceptanceClaim,
  AcceptedAccount,
  Invitation,
  IssueInvitationInput,
  InvitationTransition,
} from "../types/invitation";

export async function getInvitations(
  familySlug: string,
  signal?: AbortSignal,
): Promise<Invitation[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Invitation[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/invitations`,
      { signal },
    ),
  );
}

export async function issueInvitation(
  familySlug: string,
  input: IssueInvitationInput,
): Promise<Invitation> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<Invitation>>(
      `/api/families/${encodeURIComponent(familySlug)}/invitations`,
      input,
    ),
  );
}

export async function transitionInvitation(
  familySlug: string,
  invitationId: number,
  action: InvitationTransition,
): Promise<Invitation> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<Invitation>>(
      `/api/families/${encodeURIComponent(familySlug)}/invitations/${String(invitationId)}/${action}`,
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
