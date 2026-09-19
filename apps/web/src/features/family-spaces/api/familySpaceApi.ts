import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { CreateFamilySpaceInput, FamilySpace } from "../types/familySpace";
import type { FamilyMembership, FamilySpaceRole } from "../types/familySpace";

export async function getFamilySpaces(
  signal?: AbortSignal,
): Promise<FamilySpace[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilySpace[]>>("/api/family-spaces", {
      signal,
    }),
  );
}

export async function createFamilySpace(
  input: CreateFamilySpaceInput,
): Promise<FamilySpace> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<FamilySpace>>("/api/family-spaces", input),
  );
}

export async function getFamilySpace(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilySpace> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilySpace>>(
      `/api/families/${encodeURIComponent(familySlug)}`,
      { signal },
    ),
  );
}

export async function requestFamilySpaceDeletion(
  familySlug: string,
): Promise<FamilySpace> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilySpace>>(
      `/api/families/${encodeURIComponent(familySlug)}/deletion`,
    ),
  );
}

export async function cancelFamilySpaceDeletion(
  familySlug: string,
): Promise<FamilySpace> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.delete<ApiEnvelope<FamilySpace>>(
      `/api/families/${encodeURIComponent(familySlug)}/deletion`,
    ),
  );
}

export async function getFamilySpaceMemberships(
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

export async function updateFamilySpaceMembership(
  familySlug: string,
  membershipId: string,
  role: FamilySpaceRole,
): Promise<FamilyMembership> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<FamilyMembership>>(
      `/api/families/${encodeURIComponent(familySlug)}/memberships/${encodeURIComponent(membershipId)}`,
      { role },
    ),
  );
}

export async function removeFamilySpaceMembership(
  familySlug: string,
  membershipId: string,
): Promise<FamilyMembership> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.delete<ApiEnvelope<FamilyMembership>>(
      `/api/families/${encodeURIComponent(familySlug)}/memberships/${encodeURIComponent(membershipId)}`,
    ),
  );
}
