import { type SyntheticEvent, useState } from "react";
import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";

import { MediaUploadBatchStatus } from "../components/MediaUploadBatchStatus";
import { useMediaUploadBatchQuery } from "../hooks/useMediaUploadBatchQuery";
import { useMediaProcessingRetryMutation } from "../hooks/useMediaProcessingRetryMutation";
import {
  createMediaUploadBatch,
  useMediaUploadMutation,
} from "../hooks/useMediaUploadMutation";
import type {
  MediaUploadBatchInput,
  MediaUploadBatchResult,
} from "../types/mediaUpload";

export function MediaUploadPage() {
  const { familySlug = "" } = useParams();
  const uploadMutation = useMediaUploadMutation(familySlug);
  const [selection, setSelection] = useState<MediaUploadBatchInput | null>(
    null,
  );
  const [lastResult, setLastResult] = useState<MediaUploadBatchResult | null>(
    null,
  );
  const serverBatchId = lastResult?.batch_id ?? null;
  const batchQuery = useMediaUploadBatchQuery(familySlug, serverBatchId);
  const processingRetry = useMediaProcessingRetryMutation(
    familySlug,
    serverBatchId,
  );

  function uploadSelection() {
    if (selection !== null) {
      uploadMutation.mutate(selection, { onSuccess: setLastResult });
    }
  }

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (selection === null) {
      return;
    }

    uploadSelection();
  }

  function selectFiles(files: File[]) {
    setSelection(files.length === 0 ? null : createMediaUploadBatch(files));
    setLastResult(null);
    uploadMutation.reset();
    processingRetry.reset();
  }

  return (
    <main className="auth media-upload" aria-labelledby="media-upload-title">
      <p className="eyebrow">fambam</p>
      <h1 id="media-upload-title">Upload family photographs</h1>
      <p>
        The original uploads directly to private object storage and will be
        checked before it can be used.
      </p>
      <form onSubmit={submit}>
        <label htmlFor="media-file">Photographs</label>
        <input
          id="media-file"
          name="media-file"
          type="file"
          multiple
          accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
          onChange={(event) => {
            selectFiles(Array.from(event.target.files ?? []));
          }}
          required
        />
        <button
          type="submit"
          disabled={selection === null || uploadMutation.isPending}
        >
          {uploadMutation.isPending ? "Uploading…" : "Upload photographs"}
        </button>
      </form>
      {uploadMutation.isError && (
        <p role="alert">
          {toAppError(uploadMutation.error).message ||
            "The photograph could not be uploaded."}
        </p>
      )}
      {lastResult !== null && (
        <MediaUploadBatchStatus
          result={lastResult}
          status={batchQuery.data}
          statusPending={batchQuery.isPending && serverBatchId !== null}
          statusError={batchQuery.isError}
          retryPending={uploadMutation.isPending}
          processingRetryId={
            processingRetry.isPending ? processingRetry.variables : null
          }
          onRetry={uploadSelection}
          onProcessingRetry={(mediaUploadId) => {
            processingRetry.mutate(mediaUploadId);
          }}
        />
      )}
      {processingRetry.isError && (
        <p role="alert">Processing could not be retried.</p>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
