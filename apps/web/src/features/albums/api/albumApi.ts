import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { Album, CreateAlbumInput } from "../types/album";
import type { MediaUpload } from "@/features/media-uploads/types/mediaUpload";

function albumsPath(familySlug: string) {
  return `/api/families/${encodeURIComponent(familySlug)}/albums`;
}

export async function getAlbums(familySlug: string, signal?: AbortSignal) {
  return unwrap(
    await apiClient.get<ApiEnvelope<Album[]>>(albumsPath(familySlug), {
      signal,
    }),
  );
}

export async function getAlbum(
  familySlug: string,
  albumId: string,
  signal?: AbortSignal,
): Promise<Album> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Album>>(
      `${albumsPath(familySlug)}/${encodeURIComponent(albumId)}`,
      { signal },
    ),
  );
}

export async function createAlbum(familySlug: string, input: CreateAlbumInput) {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<Album>>(albumsPath(familySlug), input),
  );
}

export async function addPhotoToAlbum(
  familySlug: string,
  albumId: string,
  photoId: string,
  confirmVisibilityWidening: boolean,
) {
  await ensureCsrfCookie();
  await apiClient.post(
    `${albumsPath(familySlug)}/${encodeURIComponent(albumId)}/photos`,
    {
      photo_id: photoId,
      confirm_visibility_widening: confirmVisibilityWidening,
    },
  );
}

export async function removePhotoFromAlbum(
  familySlug: string,
  albumId: string,
  photoId: string,
) {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${albumsPath(familySlug)}/${encodeURIComponent(albumId)}/photos/${encodeURIComponent(photoId)}`,
  );
}

export async function uploadPhotoToAlbum(
  familySlug: string,
  albumId: string,
  file: File,
): Promise<MediaUpload> {
  await ensureCsrfCookie();
  const initiated = unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `${albumsPath(familySlug)}/${encodeURIComponent(albumId)}/media-uploads`,
      { client_filename: file.name, client_mime_type: file.type || null },
      { headers: { "Idempotency-Key": crypto.randomUUID() } },
    ),
  );
  if (initiated.state !== "initiated") return initiated;
  if (initiated.upload_authorization === null) {
    throw new Error("Upload authority was not returned for this file.");
  }
  const stored = await fetch(initiated.upload_authorization.url, {
    method: "PUT",
    headers: initiated.upload_authorization.headers,
    body: file,
  });
  if (!stored.ok)
    throw new Error(
      `Object storage rejected the upload (${String(stored.status)}).`,
    );
  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `/api/families/${encodeURIComponent(familySlug)}/media-uploads/${encodeURIComponent(initiated.id)}/complete`,
    ),
  );
}
