import type { CSSProperties, ReactNode } from "react";

export type UploadQueueRow = {
  key: string;
  filename: string;
  mediaUploadId?: string;
  previewUrl?: string;
  description: string;
  badge: string;
  tone: "uploading" | "processing" | "ready" | "attention" | "cancelled";
  progress: number | null;
  action?:
    "retry-upload" | "retry-processing" | "review-duplicate" | "retry-photo";
  actionPending?: boolean;
};

type MediaUploadBatchStatusProps = {
  rows: UploadQueueRow[];
  scope: "generic" | "album";
  collapsed: boolean;
  completionPanel?: ReactNode;
  onCollapsedChange: (collapsed: boolean) => void;
  onAction: (row: UploadQueueRow) => void;
};

const actionLabels: Record<NonNullable<UploadQueueRow["action"]>, string> = {
  "retry-upload": "Retry upload",
  "retry-processing": "Retry processing",
  "review-duplicate": "Review duplicate",
  "retry-photo": "Retry Photo creation",
};

export function MediaUploadBatchStatus({
  rows,
  scope,
  collapsed,
  completionPanel,
  onCollapsedChange,
  onAction,
}: MediaUploadBatchStatusProps) {
  const ready = rows.filter((row) => row.tone === "ready").length;
  const processing = rows.filter((row) =>
    ["uploading", "processing"].includes(row.tone),
  ).length;
  const attention = rows.filter((row) => row.tone === "attention").length;
  const cancelled = rows.filter((row) => row.tone === "cancelled").length;
  const completed = ready + attention + cancelled;
  const percent = Math.round((completed / Math.max(rows.length, 1)) * 100);

  return (
    <section className="upload-queue" aria-labelledby="upload-results-title">
      <div className="upload-queue__head">
        <h2 id="upload-results-title">
          {processing > 0
            ? `Uploading ${String(rows.length)} ${rows.length === 1 ? "photograph" : "photographs"}`
            : `${String(rows.length)} ${rows.length === 1 ? "photograph" : "photographs"}`}
        </h2>
        <button
          type="button"
          className="upload-queue__collapse"
          aria-expanded={!collapsed}
          aria-controls="upload-queue-list"
          onClick={() => {
            onCollapsedChange(!collapsed);
          }}
        >
          {collapsed ? "Expand" : "Collapse"}
          <ChevronDownIcon />
        </button>
      </div>
      <div className="upload-queue__summaries">
        <div className="upload-drawer" role="status" aria-live="polite">
          <div
            className="upload-drawer__ring"
            style={
              {
                "--upload-progress": `${String(percent * 3.6)}deg`,
              } as CSSProperties
            }
            aria-hidden="true"
          >
            {String(percent)}%
          </div>
          <div>
            <b>
              {processing > 0
                ? "Upload continues in the background"
                : attention > 0
                  ? "Some photographs need your attention"
                  : "Upload complete"}
            </b>
            <span>
              {String(ready)} ready · {String(processing)} processing ·{" "}
              {String(attention)} needs attention
              {cancelled > 0 ? ` · ${String(cancelled)} cancelled` : ""}
            </span>
          </div>
          <button
            type="button"
            onClick={() => {
              onCollapsedChange(false);
            }}
          >
            View progress
          </button>
        </div>
        {completionPanel}
      </div>
      <p className="upload-queue__description">
        {scope === "album"
          ? "The Album fills in as each one becomes ready."
          : "Each Photo is saved to the Family Space as it becomes ready."}
      </p>
      {!collapsed && (
        <div id="upload-queue-list" className="upload-list">
          {rows.map((row) => (
            <article className="upload-row" key={row.key}>
              <span className="upload-row__thumb" aria-hidden="true">
                {row.previewUrl === undefined ? (
                  <ImageIcon />
                ) : (
                  <img src={row.previewUrl} alt="" />
                )}
              </span>
              <div className="upload-row__body">
                <b>{row.filename}</b>
                <small>{row.description}</small>
                <progress
                  value={row.progress === null ? undefined : row.progress}
                  max={100}
                  aria-label={`${row.filename} upload progress`}
                  aria-valuetext={row.description}
                />
              </div>
              {row.action === undefined ? (
                <span className={`upload-status upload-status--${row.tone}`}>
                  {row.badge}
                </span>
              ) : (
                <button
                  type="button"
                  className="upload-attention"
                  disabled={row.actionPending}
                  onClick={() => {
                    onAction(row);
                  }}
                >
                  {row.actionPending ? "Working…" : actionLabels[row.action]}
                </button>
              )}
            </article>
          ))}
        </div>
      )}
    </section>
  );
}

function ChevronDownIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
    >
      <path d="m6 9 6 6 6-6" />
    </svg>
  );
}

function ImageIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
    >
      <rect x="3" y="4" width="18" height="16" rx="2" />
      <circle cx="9" cy="10" r="2" />
      <path d="m21 15-5-5L5 20" />
    </svg>
  );
}
