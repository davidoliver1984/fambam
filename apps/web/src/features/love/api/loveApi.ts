import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { LoveSummary, LoveTarget } from "../types/love";

const targetSegments: Record<LoveTarget, string> = {
  album: "albums",
  event: "events",
  story: "stories",
};

function path(
  familySlug: string,
  targetType: LoveTarget,
  targetId: string,
): string {
  return `/api/families/${encodeURIComponent(familySlug)}/${targetSegments[targetType]}/${encodeURIComponent(targetId)}/love`;
}

export async function getLoveSummary(
  familySlug: string,
  targetType: LoveTarget,
  targetId: string,
  signal?: AbortSignal,
): Promise<LoveSummary> {
  return unwrap(
    await apiClient.get<ApiEnvelope<LoveSummary>>(
      path(familySlug, targetType, targetId),
      { signal },
    ),
  );
}

export async function saveLove(
  familySlug: string,
  targetType: LoveTarget,
  targetId: string,
): Promise<LoveSummary> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<LoveSummary>>(
      path(familySlug, targetType, targetId),
    ),
  );
}

export async function removeLove(
  familySlug: string,
  targetType: LoveTarget,
  targetId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(path(familySlug, targetType, targetId));
}
