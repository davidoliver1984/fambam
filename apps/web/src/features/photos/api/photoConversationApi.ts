import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  PhotoConversation,
  PhotoReactionType,
  PhotoTextContent,
} from "../types/photoConversation";

function path(familySlug: string, photoId: string) {
  return `/api/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photoId)}`;
}
export async function getPhotoConversation(
  familySlug: string,
  photoId: string,
  albumId?: string,
  signal?: AbortSignal,
) {
  return unwrap(
    await apiClient.get<ApiEnvelope<PhotoConversation>>(
      `${path(familySlug, photoId)}/conversation`,
      { signal, params: albumId === undefined ? {} : { album_id: albumId } },
    ),
  );
}
export async function createPhotoText(
  familySlug: string,
  photoId: string,
  kind: "stories" | "comments",
  body: string,
  albumId?: string,
) {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoTextContent>>(
      `${path(familySlug, photoId)}/${kind}`,
      { body, ...(kind === "comments" ? { album_id: albumId } : {}) },
    ),
  );
}
export async function updatePhotoText(
  familySlug: string,
  photoId: string,
  kind: "stories" | "comments",
  contentId: string,
  body: string,
) {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<PhotoTextContent>>(
      `${path(familySlug, photoId)}/${kind}/${encodeURIComponent(contentId)}`,
      { body },
    ),
  );
}
export async function removePhotoText(
  familySlug: string,
  photoId: string,
  kind: "stories" | "comments",
  contentId: string,
) {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${path(familySlug, photoId)}/${kind}/${encodeURIComponent(contentId)}`,
  );
}
export async function savePhotoReaction(
  familySlug: string,
  photoId: string,
  reaction: PhotoReactionType,
  albumId: string,
) {
  await ensureCsrfCookie();
  await apiClient.put(`${path(familySlug, photoId)}/reaction`, {
    reaction,
    album_id: albumId,
  });
}
export async function removePhotoReaction(
  familySlug: string,
  photoId: string,
  albumId: string,
) {
  await ensureCsrfCookie();
  await apiClient.delete(`${path(familySlug, photoId)}/reaction`, {
    params: { album_id: albumId },
  });
}
