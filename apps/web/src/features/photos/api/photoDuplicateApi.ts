import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  MediaUploadDuplicateHold,
  ResolveDuplicateHoldInput,
} from "../types/photo";

function holdsPath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/media-upload-duplicate-holds`;
}

export async function getDuplicateHolds(
  familySlug: string,
  signal?: AbortSignal,
): Promise<MediaUploadDuplicateHold[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<MediaUploadDuplicateHold[]>>(
      holdsPath(familySlug),
      { signal },
    ),
  );
}

export async function resolveDuplicateHold(
  familySlug: string,
  input: ResolveDuplicateHoldInput,
): Promise<{
  outcome: ResolveDuplicateHoldInput["resolution"];
  photo_id: string | null;
}> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<
      ApiEnvelope<{
        outcome: ResolveDuplicateHoldInput["resolution"];
        photo_id: string | null;
      }>
    >(`${holdsPath(familySlug)}/${encodeURIComponent(input.holdId)}/resolve`, {
      resolution: input.resolution,
      existing_photo_id: input.existing_photo_id,
      disclosed_photo_ids: input.disclosed_photo_ids,
      confirm_visibility_widening: input.confirm_visibility_widening,
    }),
  );
}
