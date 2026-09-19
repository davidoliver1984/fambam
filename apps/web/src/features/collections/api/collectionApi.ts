import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { CollectionInput, FamilyCollection } from "../types/collection";

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
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post(`${path(familySlug, collectionId)}/exports`);
}
