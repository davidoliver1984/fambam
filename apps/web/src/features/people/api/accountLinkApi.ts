import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  FamilyMembership,
  PersonAccountClaim,
  PersonAccountLink,
} from "../types/person";

function personPath(familySlug: string, personId: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(personId)}`;
}

export async function getAccountLinkClaims(
  familySlug: string,
  personId: string,
  signal?: AbortSignal,
): Promise<PersonAccountClaim[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PersonAccountClaim[]>>(
      `${personPath(familySlug, personId)}/account-link-claims`,
      { signal },
    ),
  );
}

export async function proposeAccountLink(
  familySlug: string,
  personId: string,
): Promise<PersonAccountClaim> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonAccountClaim>>(
      `${personPath(familySlug, personId)}/account-link-claims`,
    ),
  );
}

export async function resolveAccountLinkClaim(
  familySlug: string,
  personId: string,
  claimId: string,
  resolution: "approve" | "reject",
): Promise<PersonAccountClaim | PersonAccountLink> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PersonAccountClaim | PersonAccountLink>>(
      `${personPath(familySlug, personId)}/account-link-claims/${encodeURIComponent(claimId)}/${resolution}`,
    ),
  );
}

export async function getFamilyMemberships(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyMembership[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyMembership[]>>(
      `/api/families/${encodeURIComponent(familySlug)}/memberships`,
      { signal },
    ),
  );
}

export async function assignAccountLink(
  familySlug: string,
  personId: string,
  membershipId: string,
): Promise<PersonAccountLink> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<PersonAccountLink>>(
      `${personPath(familySlug, personId)}/account-link`,
      { membership_id: membershipId },
    ),
  );
}

export async function removeAccountLink(
  familySlug: string,
  personId: string,
): Promise<null> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.delete<ApiEnvelope<null>>(
      `${personPath(familySlug, personId)}/account-link`,
    ),
  );
}
