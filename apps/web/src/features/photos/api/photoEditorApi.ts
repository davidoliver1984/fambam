import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  EditRecipe,
  PhotoEditPreview,
  PhotoVersion,
  PhotoVersions,
  RestorePreviewResult,
  VersionDelivery,
} from "../types/photoEditor";

function editorPath(familySlug: string, photoId: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/photos/${encodeURIComponent(photoId)}`;
}

export async function getPhotoVersions(
  familySlug: string,
  photoId: string,
  signal?: AbortSignal,
): Promise<PhotoVersions> {
  return unwrap(
    await apiClient.get<ApiEnvelope<PhotoVersions>>(
      `${editorPath(familySlug, photoId)}/versions`,
      { signal },
    ),
  );
}

export async function getPhotoVersionDelivery(
  familySlug: string,
  photoId: string,
  versionId: string,
  signal?: AbortSignal,
): Promise<VersionDelivery> {
  return unwrap(
    await apiClient.get<ApiEnvelope<VersionDelivery>>(
      `${editorPath(familySlug, photoId)}/versions/${encodeURIComponent(versionId)}/delivery`,
      { signal },
    ),
  );
}

export async function getPhotoEditPreviewDelivery(
  familySlug: string,
  photoId: string,
  previewId: string,
  signal?: AbortSignal,
): Promise<VersionDelivery> {
  return unwrap(
    await apiClient.get<ApiEnvelope<VersionDelivery>>(
      `${editorPath(familySlug, photoId)}/edit-previews/${encodeURIComponent(previewId)}/delivery`,
      { signal },
    ),
  );
}

export async function createPhotoEditPreview(
  familySlug: string,
  photoId: string,
  editRecipe: EditRecipe,
): Promise<PhotoEditPreview> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoEditPreview>>(
      `${editorPath(familySlug, photoId)}/edit-previews`,
      { edit_recipe: editRecipe },
    ),
  );
}

export async function createRestorePreview(
  familySlug: string,
  photoId: string,
): Promise<RestorePreviewResult> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<RestorePreviewResult>>(
      `${editorPath(familySlug, photoId)}/restore-previews`,
    ),
  );
}

export async function applyPhotoEditPreview(
  familySlug: string,
  photoId: string,
  previewId: string,
): Promise<PhotoVersion> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<PhotoVersion>>(
      `${editorPath(familySlug, photoId)}/edit-previews/${encodeURIComponent(previewId)}/apply`,
    ),
  );
}

export async function discardPhotoEditPreview(
  familySlug: string,
  photoId: string,
  previewId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${editorPath(familySlug, photoId)}/edit-previews/${encodeURIComponent(previewId)}`,
  );
}

export async function activatePhotoVersion(
  familySlug: string,
  photoId: string,
  versionId: string | null,
): Promise<{ active_photo_version_id: string | null }> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.put<
      ApiEnvelope<{ active_photo_version_id: string | null }>
    >(`${editorPath(familySlug, photoId)}/active-version`, {
      photo_version_id: versionId,
    }),
  );
}
