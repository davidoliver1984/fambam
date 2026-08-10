import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type { MediaUpload } from "../types/mediaUpload";

function mediaUploadsPath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/media-uploads`;
}

export async function initiateMediaUpload(
  familySlug: string,
  file: File,
  idempotencyKey: string,
): Promise<MediaUpload> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      mediaUploadsPath(familySlug),
      {
        client_filename: file.name,
        client_mime_type: file.type || null,
      },
      { headers: { "Idempotency-Key": idempotencyKey } },
    ),
  );
}

export async function putStagedObject(
  authorization: NonNullable<MediaUpload["upload_authorization"]>,
  file: File,
): Promise<void> {
  const response = await fetch(authorization.url, {
    method: authorization.method,
    headers: authorization.headers,
    body: file,
  });

  if (!response.ok) {
    throw new Error(
      `Object storage rejected the upload (${String(response.status)}).`,
    );
  }
}

export async function completeMediaUpload(
  familySlug: string,
  mediaUploadId: string,
): Promise<MediaUpload> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `${mediaUploadsPath(familySlug)}/${encodeURIComponent(mediaUploadId)}/complete`,
    ),
  );
}

export async function uploadMediaFile(
  familySlug: string,
  file: File,
  idempotencyKey: string,
): Promise<MediaUpload> {
  const initiated = await initiateMediaUpload(familySlug, file, idempotencyKey);

  if (initiated.state !== "initiated") {
    return initiated;
  }
  if (initiated.upload_authorization === null) {
    throw new Error("Upload authority was not returned for this file.");
  }

  await putStagedObject(initiated.upload_authorization, file);

  return completeMediaUpload(familySlug, initiated.id);
}
