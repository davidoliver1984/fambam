import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { FamilyCircle, FamilyCircleInput } from "../types/person";

function circlesPath(familySlug: string) {
  return `/api/families/${encodeURIComponent(familySlug)}/circles`;
}

export async function getFamilyCircles(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyCircle[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyCircle[]>>(circlesPath(familySlug), {
      signal,
    }),
  );
}

export async function createFamilyCircle(
  familySlug: string,
  input: FamilyCircleInput,
): Promise<FamilyCircle> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCircle>>(
      circlesPath(familySlug),
      input,
    ),
  );
}

export async function updateFamilyCircle(
  familySlug: string,
  circleId: string,
  input: FamilyCircleInput,
): Promise<FamilyCircle> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<FamilyCircle>>(
      `${circlesPath(familySlug)}/${encodeURIComponent(circleId)}`,
      input,
    ),
  );
}

export async function deleteFamilyCircle(
  familySlug: string,
  circleId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${circlesPath(familySlug)}/${encodeURIComponent(circleId)}`,
  );
}

export async function addPersonToCircle(
  familySlug: string,
  circleId: string,
  personId: string,
): Promise<FamilyCircle> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCircle>>(
      `${circlesPath(familySlug)}/${encodeURIComponent(circleId)}/people`,
      { person_id: personId },
    ),
  );
}

export async function removePersonFromCircle(
  familySlug: string,
  circleId: string,
  personId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${circlesPath(familySlug)}/${encodeURIComponent(circleId)}/people/${encodeURIComponent(personId)}`,
  );
}
