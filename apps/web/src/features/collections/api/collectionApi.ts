import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { FamilyExport } from "@/features/exports/types/familyExport";

import type {
  CollectionInput,
  CollectionUpdateInput,
  FamilyCollection,
} from "../types/collection";

function path(familySlug: string, collectionId?: string): string {
  const base = `/api/families/${encodeURIComponent(familySlug)}/collections`;
  return collectionId === undefined
    ? base
    : `${base}/${encodeURIComponent(collectionId)}`;
}

export async function getCollections(
  familySlug: string,
  signal?: AbortSignal,
): Promise<FamilyCollection[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyCollection[]>>(path(familySlug), {
      signal,
    }),
  );
}
export async function getCollection(
  familySlug: string,
  collectionId: string,
  signal?: AbortSignal,
): Promise<FamilyCollection> {
  return unwrap(
    await apiClient.get<ApiEnvelope<FamilyCollection>>(
      path(familySlug, collectionId),
      { signal },
    ),
  );
}
export async function createCollection(
  familySlug: string,
  input: CollectionInput,
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCollection>>(
      path(familySlug),
      input,
    ),
  );
}
export async function updateCollection(
  familySlug: string,
  collectionId: string,
  input: CollectionUpdateInput,
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<FamilyCollection>>(
      path(familySlug, collectionId),
      input,
    ),
  );
}
export async function addCollectionPhoto(
  familySlug: string,
  collectionId: string,
  photoId: string,
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCollection>>(
      `${path(familySlug, collectionId)}/photos`,
      { photo_id: photoId },
    ),
  );
}
export async function addCollectionPhotos(
  familySlug: string,
  collectionId: string,
  photoIds: string[],
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCollection>>(
      `${path(familySlug, collectionId)}/photos/batch`,
      { photo_ids: photoIds },
    ),
  );
}
export async function populateCollection(
  familySlug: string,
  collectionId: string,
  sourceType: "album" | "event",
  sourceId: string,
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyCollection>>(
      `${path(familySlug, collectionId)}/populate`,
      { source_type: sourceType, source_id: sourceId },
    ),
  );
}
export async function removeCollectionPhoto(
  familySlug: string,
  collectionId: string,
  photoId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${path(familySlug, collectionId)}/photos/${encodeURIComponent(photoId)}`,
  );
}
export async function reorderCollectionPhotos(
  familySlug: string,
  collectionId: string,
  photoIds: string[],
): Promise<FamilyCollection> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<ApiEnvelope<FamilyCollection>>(
      `${path(familySlug, collectionId)}/order`,
      { photo_ids: photoIds },
    ),
  );
}
export async function deleteCollection(
  familySlug: string,
  collectionId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(path(familySlug, collectionId));
}
export async function requestCollectionExport(
  familySlug: string,
  collectionId: string,
): Promise<FamilyExport> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<FamilyExport>>(
      `${path(familySlug, collectionId)}/exports`,
    ),
  );
}
