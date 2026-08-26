import { apiClient, apiUrl, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  DuplicateCandidate,
  DuplicateDecision,
  DuplicatePhotoSummary,
} from "../types/duplicate";

type WirePhotoSummary = Omit<DuplicatePhotoSummary, "image_url">;
type WireCandidate = Omit<DuplicateCandidate, "photo" | "candidate_photo"> & {
  photo: WirePhotoSummary;
  candidate_photo: WirePhotoSummary;
};
type WireDecision = Omit<DuplicateDecision, "photo" | "candidate_photo"> & {
  photo: WirePhotoSummary;
  candidate_photo: WirePhotoSummary;
};

function base(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}`;
}

function photo(
  familySlug: string,
  value: WirePhotoSummary,
): DuplicatePhotoSummary {
  return {
    ...value,
    image_url: apiUrl(
      `${base(familySlug)}/media-uploads/${encodeURIComponent(value.media_upload_id)}/canonical`,
    ),
  };
}

function candidate(
  familySlug: string,
  value: WireCandidate,
): DuplicateCandidate {
  return {
    ...value,
    photo: photo(familySlug, value.photo),
    candidate_photo: photo(familySlug, value.candidate_photo),
  };
}

function decision(familySlug: string, value: WireDecision): DuplicateDecision {
  return {
    ...value,
    photo: photo(familySlug, value.photo),
    candidate_photo: photo(familySlug, value.candidate_photo),
  };
}

export async function getDuplicateCandidates(
  familySlug: string,
  signal?: AbortSignal,
): Promise<DuplicateCandidate[]> {
  const values = unwrap(
    await apiClient.get<ApiEnvelope<WireCandidate[]>>(
      `${base(familySlug)}/duplicate-candidates`,
      { signal },
    ),
  );
  return values.map((value) => candidate(familySlug, value));
}

export async function getDuplicateDecisions(
  familySlug: string,
  signal?: AbortSignal,
): Promise<DuplicateDecision[]> {
  const values = unwrap(
    await apiClient.get<ApiEnvelope<WireDecision[]>>(
      `${base(familySlug)}/duplicate-decisions`,
      { signal },
    ),
  );
  return values.map((value) => decision(familySlug, value));
}

export async function dismissDuplicateCandidate(
  familySlug: string,
  candidateId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/duplicate-candidates/${encodeURIComponent(candidateId)}/dismiss`,
  );
}

export async function reopenDuplicateDecision(
  familySlug: string,
  decisionId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/duplicate-decisions/${encodeURIComponent(decisionId)}/reopen`,
  );
}

export async function flagPhotoDuplicate(
  familySlug: string,
  photoId: string,
  candidatePhotoId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(
    `${base(familySlug)}/photos/${encodeURIComponent(photoId)}/duplicate-flags`,
    { candidate_photo_id: candidatePhotoId },
  );
}
