import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import { toAppError } from "@/api/errors";

import type {
  MediaDelivery,
  MediaUpload,
  MediaUploadBatchInput,
  MediaUploadProgress,
  MediaUploadBatchResult,
  MediaUploadBatchStatus,
  MediaVariantTransform,
} from "../types/mediaUpload";

function mediaUploadsPath(familySlug: string): string {
  return `/api/families/${encodeURIComponent(familySlug)}/media-uploads`;
}

export async function getMediaVariantDelivery(
  familySlug: string,
  mediaUploadId: string,
  transform: MediaVariantTransform,
  signal?: AbortSignal,
): Promise<MediaDelivery> {
  return unwrap(
    await apiClient.get<ApiEnvelope<MediaDelivery>>(
      `${mediaUploadsPath(familySlug)}/${encodeURIComponent(mediaUploadId)}/variants/${encodeURIComponent(transform)}`,
      { signal },
    ),
  );
}

export async function getOriginalMediaDelivery(
  familySlug: string,
  mediaUploadId: string,
): Promise<MediaDelivery> {
  return unwrap(
    await apiClient.get<ApiEnvelope<MediaDelivery>>(
      `${mediaUploadsPath(familySlug)}/${encodeURIComponent(mediaUploadId)}/original`,
    ),
  );
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
  onProgress?: (loaded: number, total: number) => void,
): Promise<void> {
  if (onProgress !== undefined && typeof XMLHttpRequest !== "undefined") {
    await putStagedObjectWithProgress(authorization, file, onProgress);
    return;
  }

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

function putStagedObjectWithProgress(
  authorization: NonNullable<MediaUpload["upload_authorization"]>,
  file: File,
  onProgress: (loaded: number, total: number) => void,
): Promise<void> {
  return new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open(authorization.method, authorization.url);
    Object.entries(authorization.headers).forEach(([name, value]) => {
      request.setRequestHeader(name, value);
    });
    request.upload.addEventListener("progress", (event) => {
      onProgress(
        event.loaded,
        event.lengthComputable ? event.total : file.size,
      );
    });
    request.addEventListener("load", () => {
      if (request.status >= 200 && request.status < 300) {
        onProgress(file.size, file.size);
        resolve();
        return;
      }
      reject(
        new Error(
          `Object storage rejected the upload (${String(request.status)}).`,
        ),
      );
    });
    request.addEventListener("error", () => {
      reject(new Error("The upload could not reach object storage."));
    });
    request.send(file);
  });
}

export async function initiateAlbumMediaUpload(
  familySlug: string,
  albumId: string,
  file: File,
  idempotencyKey: string,
  uploadBatchId: string,
): Promise<MediaUpload> {
  await ensureCsrfCookie();

  return unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `/api/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(albumId)}/media-uploads`,
      {
        client_filename: file.name,
        client_mime_type: file.type || null,
        upload_batch_id: uploadBatchId,
      },
      { headers: { "Idempotency-Key": idempotencyKey } },
    ),
  );
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
  targetAlbumId?: string,
  onProgress?: (loaded: number, total: number) => void,
): Promise<MediaUpload> {
  const initiated =
    targetAlbumId === undefined || uploadBatchId === undefined
      ? await initiateMediaUpload(
          familySlug,
          file,
          idempotencyKey,
          uploadBatchId,
        )
      : await initiateAlbumMediaUpload(
          familySlug,
          targetAlbumId,
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

  await putStagedObject(initiated.upload_authorization, file, onProgress);

  return completeMediaUpload(familySlug, initiated.id);
}

export async function uploadMediaBatch(
  familySlug: string,
  input: MediaUploadBatchInput,
  targetAlbumId?: string,
  onProgress?: (progress: MediaUploadProgress) => void,
): Promise<MediaUploadBatchResult> {
  const outcomes = await Promise.all(
    input.items.map(async ({ file, idempotencyKey }) => {
      try {
        const upload = await uploadMediaFile(
          familySlug,
          file,
          idempotencyKey,
          input.batchId,
          targetAlbumId,
          onProgress === undefined
            ? undefined
            : (loaded, total) => {
                onProgress({
                  itemKey: idempotencyKey,
                  loaded,
                  total,
                  percent: total === 0 ? 0 : Math.round((loaded / total) * 100),
                });
              },
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
