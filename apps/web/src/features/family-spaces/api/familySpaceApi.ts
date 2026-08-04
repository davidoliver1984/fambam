import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { CreateFamilySpaceInput, FamilySpace } from "../types/familySpace";

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
