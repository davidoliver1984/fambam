import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  CreatePhotoInput,
  Photo,
  PhotoProposalResolution,
  PhotoProvenanceInput,
  PhotoProvenanceProposal,
  UpdatePhotoInput,
} from "../types/photo";

function photosPath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/photos`;
}

function photoPath(familySlug: string, photoId: string): string {
  return `${photosPath(familySlug)}/${encodeURIComponent(photoId)}`;
}

export async function getPhotos(
  familySlug: string,
  signal?: AbortSignal,
): Promise<Photo[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Photo[]>>(photosPath(familySlug), {
      signal,
    }),
  );
}

export async function getPhoto(
  familySlug: string,
  photoId: string,
  signal?: AbortSignal,
): Promise<Photo> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Photo>>(photoPath(familySlug, photoId), {
      signal,
    }),
  );
}

export async function createPhoto(
  familySlug: string,
  input: CreatePhotoInput,
): Promise<Photo> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<Photo>>(photosPath(familySlug), input),
  );
}

export async function updatePhoto(
  familySlug: string,
  photoId: string,
  input: UpdatePhotoInput,
): Promise<Photo> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<Photo>>(
      photoPath(familySlug, photoId),
      input,
    ),
  );
}

export async function replacePhotoTags(
  familySlug: string,
  photoId: string,
  tags: string[],
): Promise<Photo> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<Photo>>(
      `${photoPath(familySlug, photoId)}/tags`,
      { tags },
    ),
  );
}

export async function submitPhotoProvenance(
  familySlug: string,
  photoId: string,
  input: PhotoProvenanceInput,
): Promise<PhotoProvenanceProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoProvenanceProposal>>(
      `${photoPath(familySlug, photoId)}/provenance-proposals`,
      input,
    ),
  );
}

export async function getPhotoProvenanceProposals(
  familySlug: string,
  photoId: string,
  signal?: AbortSignal,
): Promise<PhotoProvenanceProposal[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PhotoProvenanceProposal[]>>(
      `${photoPath(familySlug, photoId)}/provenance-proposals`,
      { signal },
    ),
  );
}

export async function resolvePhotoProvenanceProposal(
  familySlug: string,
  photoId: string,
  proposalId: string,
  resolution: PhotoProposalResolution,
): Promise<PhotoProvenanceProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoProvenanceProposal>>(
      `${photoPath(familySlug, photoId)}/provenance-proposals/${encodeURIComponent(proposalId)}/${resolution}`,
    ),
  );
}
