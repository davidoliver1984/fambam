import type {
  MediaUploadBatchResult,
  MediaUploadBatchStatus as BatchStatus,
} from "../types/mediaUpload";

type MediaUploadBatchStatusProps = {
  result: MediaUploadBatchResult;
  status: BatchStatus | undefined;
  statusPending: boolean;
  statusError: boolean;
  retryPending: boolean;
  processingRetryId: string | null;
  onRetry: () => void;
  onProcessingRetry: (mediaUploadId: string) => void;
};

export function MediaUploadBatchStatus({
  result,
  status,
  statusPending,
  statusError,
  retryPending,
  processingRetryId,
  onRetry,
  onProcessingRetry,
}: MediaUploadBatchStatusProps) {
  const clientFailures = result.outcomes.filter(
    (outcome) => outcome.status === "failed",
  );
  const acceptedCount = result.outcomes.length - clientFailures.length;

  return (
    <section aria-labelledby="upload-results-title">
      <h2 id="upload-results-title">Upload progress</h2>
      <p role="status">
        {acceptedCount} of {result.outcomes.length} files completed the direct
        upload hand-off.
      </p>
      {clientFailures.length > 0 && (
        <>
          <p role="alert">
            {clientFailures.length} files need another attempt.
          </p>
          <button type="button" onClick={onRetry} disabled={retryPending}>
            {retryPending ? "Retrying…" : "Retry incomplete files"}
          </button>
        </>
      )}
      {statusPending && <p role="status">Checking file processing…</p>}
      {statusError && (
        <p role="alert">Current processing status could not be loaded.</p>
      )}
      {status !== undefined && (
        <ul>
          {status.items.map((item) => (
            <li key={item.id}>
              {item.client_filename}: {item.state}
              {item.state === "degraded" && (
                <button
                  type="button"
                  onClick={() => {
                    onProcessingRetry(item.id);
                  }}
                  disabled={processingRetryId !== null}
                >
                  {processingRetryId === item.id
                    ? "Retrying processing…"
                    : "Retry processing"}
                </button>
              )}
            </li>
          ))}
        </ul>
      )}
      {clientFailures.length > 0 && (
        <ul>
          {clientFailures.map((outcome) => (
            <li key={outcome.item_key}>
              {outcome.client_filename}: {outcome.message}
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
