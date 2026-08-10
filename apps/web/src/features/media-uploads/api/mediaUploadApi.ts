import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import { toAppError } from "@/api/errors";

import type {
  MediaUpload,
  MediaUploadBatchInput,
  MediaUploadBatchResult,
  MediaUploadBatchStatus,
} from "../types/mediaUpload";

function mediaUploadsPath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/media-uploads`;
}

export async function initiateMediaUpload(
  familySlug: string,
  file: File,
  idempotencyKey: string,
  uploadBatchId?: string,
): Promise<MediaUpload> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      mediaUploadsPath(familySlug),
      {
        client_filename: file.name,
        client_mime_type: file.type || null,
        ...(uploadBatchId === undefined
          ? {}
          : { upload_batch_id: uploadBatchId }),
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
  uploadBatchId?: string,
): Promise<MediaUpload> {
  const initiated = await initiateMediaUpload(
    familySlug,
    file,
    idempotencyKey,
    uploadBatchId,
  );

  if (initiated.state !== "initiated") {
    return initiated;
  }
  if (initiated.upload_authorization === null) {
    throw new Error("Upload authority was not returned for this file.");
  }

  await putStagedObject(initiated.upload_authorization, file);

  return completeMediaUpload(familySlug, initiated.id);
}

export async function uploadMediaBatch(
  familySlug: string,
  input: MediaUploadBatchInput,
): Promise<MediaUploadBatchResult> {
  const outcomes = await Promise.all(
    input.items.map(async ({ file, idempotencyKey }) => {
      try {
        const upload = await uploadMediaFile(
          familySlug,
          file,
          idempotencyKey,
          input.batchId,
        );

        return {
          status: "uploaded" as const,
          item_key: idempotencyKey,
          client_filename: file.name,
          upload,
        };
      } catch (error: unknown) {
        return {
          status: "failed" as const,
          item_key: idempotencyKey,
          client_filename: file.name,
          message: toAppError(error).message,
        };
      }
    }),
  );

  return { batch_id: input.batchId, outcomes };
}

export async function retryMediaUploadProcessing(
  familySlug: string,
  mediaUploadId: string,
): Promise<MediaUpload> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `${mediaUploadsPath(familySlug)}/${encodeURIComponent(mediaUploadId)}/retry-processing`,
    ),
  );
}

export async function getMediaUploadBatch(
  familySlug: string,
  batchId: string,
  signal?: AbortSignal,
): Promise<MediaUploadBatchStatus> {
  return unwrap(
    await apiClient.get<ApiEnvelope<MediaUploadBatchStatus>>(
      `${mediaUploadsPath(familySlug).replace(/\/media-uploads$/, "/media-upload-batches")}/${encodeURIComponent(batchId)}`,
      { signal },
    ),
  );
}
