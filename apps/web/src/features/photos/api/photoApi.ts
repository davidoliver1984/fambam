import axios from "axios";

import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  CreatePhotoInput,
  CreatePhotoResult,
  DeletedPhoto,
  Photo,
  PhotoMetadataInput,
  PhotoMetadataProposal,
  PhotoPerson,
  PhotoProposalResolution,
  PhotoProvenanceInput,
  PhotoProvenanceProposal,
  PhotoFilters,
  PromotableMediaUpload,
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
  filters: PhotoFilters = {},
  signal?: AbortSignal,
): Promise<Photo[]> {
  const params = Object.fromEntries(
    Object.entries(filters).filter(
      ([, value]) => value !== "" && value !== false,
    ),
  );

  return unwrap(
    await apiClient.get<ApiEnvelope<Photo[]>>(photosPath(familySlug), {
      signal,
      params,
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

export async function getDeletedPhotos(
  familySlug: string,
  signal?: AbortSignal,
): Promise<DeletedPhoto[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<DeletedPhoto[]>>(
      `${photosPath(familySlug)}/deleted`,
      { signal },
    ),
  );
}

export async function getPromotableMediaUploads(
  familySlug: string,
  signal?: AbortSignal,
): Promise<PromotableMediaUpload[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PromotableMediaUpload[]>>(
      `${photosPath(familySlug)}/promotable-uploads`,
      { signal },
    ),
  );
}

export async function deletePhoto(
  familySlug: string,
  photoId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(photoPath(familySlug, photoId));
}

export async function restorePhoto(
  familySlug: string,
  photoId: string,
): Promise<Photo> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<Photo>>(
      `${photoPath(familySlug, photoId)}/restore`,
    ),
  );
}

export async function createPhoto(
  familySlug: string,
  input: CreatePhotoInput,
): Promise<CreatePhotoResult> {
  await ensureCsrfCookie();
  try {
    const response = await apiClient.post<ApiEnvelope<CreatePhotoResult>>(
      photosPath(familySlug),
      input,
    );
    if (response.status === 204) return { outcome: "cancelled" };
    return unwrap(response);
  } catch (error: unknown) {
    if (
      axios.isAxiosError<ApiEnvelope<CreatePhotoResult>>(error) &&
      error.response?.status === 409
    ) {
      return error.response.data.data;
    }
    throw error;
  }
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

export async function submitPhotoMetadata(
  familySlug: string,
  photoId: string,
  input: PhotoMetadataInput,
): Promise<PhotoMetadataProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoMetadataProposal>>(
      `${photoPath(familySlug, photoId)}/metadata-proposals`,
      input,
    ),
  );
}

export async function getPhotoMetadataProposals(
  familySlug: string,
  photoId: string,
  signal?: AbortSignal,
): Promise<PhotoMetadataProposal[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PhotoMetadataProposal[]>>(
      `${photoPath(familySlug, photoId)}/metadata-proposals`,
      { signal },
    ),
  );
}

export async function resolvePhotoMetadataProposal(
  familySlug: string,
  photoId: string,
  proposalId: string,
  resolution: PhotoProposalResolution,
): Promise<PhotoMetadataProposal> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoMetadataProposal>>(
      `${photoPath(familySlug, photoId)}/metadata-proposals/${encodeURIComponent(proposalId)}/${resolution}`,
    ),
  );
}

export async function submitPhotoPerson(
  familySlug: string,
  photoId: string,
  personId: string,
): Promise<PhotoPerson> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoPerson>>(
      `${photoPath(familySlug, photoId)}/people`,
      { person_id: personId },
    ),
  );
}

export async function getPhotoPersonProposals(
  familySlug: string,
  photoId: string,
  signal?: AbortSignal,
): Promise<PhotoPerson[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PhotoPerson[]>>(
      `${photoPath(familySlug, photoId)}/person-proposals`,
      { signal },
    ),
  );
}

export async function resolvePhotoPersonProposal(
  familySlug: string,
  photoId: string,
  associationId: string,
  resolution: PhotoProposalResolution,
): Promise<PhotoPerson> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoPerson>>(
      `${photoPath(familySlug, photoId)}/people/${encodeURIComponent(associationId)}/${resolution}`,
    ),
  );
}
