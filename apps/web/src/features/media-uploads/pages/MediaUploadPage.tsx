import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { useQueryClient } from "@tanstack/react-query";
import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";
import { ButtonLink, PageHeader } from "@/components/ui";
import { albumKeys } from "@/features/albums/api/albumKeys";
import { useAlbumQuery } from "@/features/albums/hooks/useAlbumQueries";
import { homeKeys } from "@/features/home/hooks/useHomeQuery";
import { createPhoto } from "@/features/photos/api/photoApi";
import { photoKeys } from "@/features/photos/api/photoKeys";
import {
  usePhotoDuplicateHoldsQuery,
  useResolvePhotoDuplicateHoldMutation,
} from "@/features/photos/hooks/usePhotoDuplicateHolds";
import type {
  CreatePhotoInput,
  DuplicatePhotoCandidate,
} from "@/features/photos/types/photo";
import { searchKeys } from "@/features/search/api/searchKeys";

import {
  MediaUploadBatchStatus,
  type UploadQueueRow,
} from "../components/MediaUploadBatchStatus";
import {
  UploadDuplicateDialog,
  type UploadDuplicateReview,
} from "../components/UploadDuplicateDialog";
import { useMediaUploadBatchQuery } from "../hooks/useMediaUploadBatchQuery";
import { useMediaProcessingRetryMutation } from "../hooks/useMediaProcessingRetryMutation";
import {
  createMediaUploadBatch,
  useMediaUploadMutation,
} from "../hooks/useMediaUploadMutation";
import type {
  MediaUploadBatchInput,
  MediaUploadBatchResult,
  MediaUploadBatchStatus as BatchStatus,
  MediaUploadProgress,
  MediaUploadState,
} from "../types/mediaUpload";

import "./MediaUploadPage.css";

type PromotionState =
  | { status: "promoting" }
  | { status: "ready"; photoId: string }
  | { status: "duplicate"; candidates: DuplicatePhotoCandidate[] }
  | { status: "failed"; message: string }
  | { status: "cancelled" };

const acceptedTypes = new Set([
  "image/jpeg",
  "image/png",
  "image/heic",
  "image/heif",
  "image/webp",
  "image/tiff",
]);

export function MediaUploadPage() {
  const { familySlug = "", albumId = "" } = useParams();
  const scope = albumId === "" ? "generic" : "album";
  const album = useAlbumQuery(familySlug, albumId);
  const queryClient = useQueryClient();
  const fileInput = useRef<HTMLInputElement>(null);
  const promotionAttempts = useRef(new Set<string>());
  const albumInvalidations = useRef(new Set<string>());
  const [selection, setSelection] = useState<MediaUploadBatchInput | null>(
    null,
  );
  const [lastResult, setLastResult] = useState<MediaUploadBatchResult | null>(
    null,
  );
  const [progress, setProgress] = useState<Record<string, number>>({});
  const [promotions, setPromotions] = useState<
    Partial<Record<string, PromotionState>>
  >({});
  const [albumResolutions, setAlbumResolutions] = useState<
    Partial<Record<string, "ready" | "cancelled">>
  >({});
  const [collapsed, setCollapsed] = useState(false);
  const [dragging, setDragging] = useState(false);
  const [selectionError, setSelectionError] = useState("");
  const [duplicateReview, setDuplicateReview] =
    useState<UploadDuplicateReview | null>(null);
  const [duplicatePending, setDuplicatePending] = useState(false);
  const [duplicateError, setDuplicateError] = useState("");

  const onProgress = useCallback((update: MediaUploadProgress) => {
    setProgress((current) => ({
      ...current,
      [update.itemKey]: update.percent,
    }));
  }, []);
  const uploadMutation = useMediaUploadMutation(
    familySlug,
    scope === "album" ? albumId : undefined,
    onProgress,
  );
  const serverBatchId = lastResult?.batch_id ?? null;
  const batchQuery = useMediaUploadBatchQuery(familySlug, serverBatchId);
  const processingRetry = useMediaProcessingRetryMutation(
    familySlug,
    serverBatchId,
  );
  const holds = usePhotoDuplicateHoldsQuery(familySlug, scope === "album");
  const duplicateHolds = holds.data ?? [];
  const refetchHolds = holds.refetch;
  const resolveHold = useResolvePhotoDuplicateHoldMutation(familySlug);

  const invalidatePhotoContinuity = useCallback(
    async (includeAlbum: boolean) => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: photoKeys.all(familySlug) }),
        queryClient.invalidateQueries({ queryKey: searchKeys.all(familySlug) }),
        queryClient.invalidateQueries({
          queryKey: homeKeys.detail(familySlug),
        }),
        ...(includeAlbum
          ? [
              queryClient.invalidateQueries({
                queryKey: albumKeys.all(familySlug),
              }),
            ]
          : []),
      ]);
    },
    [familySlug, queryClient],
  );

  const previews = useMemo<Record<string, string>>(() => {
    if (selection === null || typeof URL.createObjectURL !== "function") {
      return {};
    }
    return Object.fromEntries(
      selection.items.map((item) => [
        item.idempotencyKey,
        URL.createObjectURL(item.file),
      ]),
    );
  }, [selection]);

  useEffect(
    () => () => {
      Object.values(previews).forEach((url) => {
        URL.revokeObjectURL(url);
      });
    },
    [previews],
  );

  const promoteReadyUpload = useCallback(
    async (mediaUploadId: string) => {
      setPromotions((current) => ({
        ...current,
        [mediaUploadId]: { status: "promoting" },
      }));
      try {
        const result = await createPhoto(familySlug, photoInput(mediaUploadId));
        if (result.outcome === "duplicate_detected") {
          setPromotions((current) => ({
            ...current,
            [mediaUploadId]: {
              status: "duplicate",
              candidates: result.candidates,
            },
          }));
          return;
        }
        if (result.outcome === "cancelled") {
          setPromotions((current) => ({
            ...current,
            [mediaUploadId]: { status: "cancelled" },
          }));
          return;
        }
        setPromotions((current) => ({
          ...current,
          [mediaUploadId]: { status: "ready", photoId: result.photo.id },
        }));
        await invalidatePhotoContinuity(false);
      } catch (error: unknown) {
        setPromotions((current) => ({
          ...current,
          [mediaUploadId]: {
            status: "failed",
            message:
              toAppError(error).message || "The Photo could not be created.",
          },
        }));
      }
    },
    [familySlug, invalidatePhotoContinuity],
  );

  useEffect(() => {
    if (scope !== "generic" || batchQuery.data === undefined) return;
    batchQuery.data.items
      .filter((item) => item.state === "ready")
      .forEach((item) => {
        if (promotionAttempts.current.has(item.id)) return;
        promotionAttempts.current.add(item.id);
        void promoteReadyUpload(item.id);
      });
  }, [batchQuery.data, promoteReadyUpload, scope]);

  useEffect(() => {
    if (
      scope !== "album" ||
      !batchQuery.data?.items.some((item) => item.state === "ready")
    ) {
      return;
    }
    void refetchHolds();
  }, [batchQuery.dataUpdatedAt, batchQuery.data?.items, refetchHolds, scope]);

  useEffect(() => {
    if (scope !== "album" || batchQuery.data === undefined) return;
    batchQuery.data.items
      .filter((item) => item.state === "ready")
      .forEach((item) => {
        if (albumInvalidations.current.has(item.id)) return;
        albumInvalidations.current.add(item.id);
        void invalidatePhotoContinuity(true);
      });
  }, [batchQuery.data, invalidatePhotoContinuity, scope]);

  if (scope === "album" && album.isPending) {
    return <p role="status">Opening Album upload…</p>;
  }
  if (scope === "album" && album.isError) {
    return <p role="alert">This Album upload is unavailable.</p>;
  }
  if (scope === "album" && album.data?.permissions.can_contribute !== true) {
    return <p role="alert">You cannot add photographs to this Album.</p>;
  }

  const albumName = album.data?.name;
  const backPath =
    scope === "album"
      ? `/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(albumId)}`
      : `/families/${encodeURIComponent(familySlug)}/photos`;
  const rows = buildRows({
    selection,
    result: lastResult,
    status: batchQuery.data,
    progress,
    previews,
    promotions,
    albumHolds: duplicateHolds,
    albumResolutions,
    scope,
    retryingId: processingRetry.isPending ? processingRetry.variables : null,
  });
  const readyCount = rows.filter((row) => row.tone === "ready").length;

  function beginUpload(files: File[]) {
    const supported = files.filter(
      (file) => acceptedTypes.has(file.type) || file.type === "",
    );
    if (supported.length === 0) {
      setSelectionError(
        "Choose JPEG, PNG, HEIC, HEIF, WebP or TIFF photographs.",
      );
      return;
    }
    const batch = createMediaUploadBatch(supported);
    setSelectionError(
      supported.length === files.length
        ? ""
        : "Some unsupported files were not added to the upload.",
    );
    setSelection(batch);
    setLastResult(null);
    setProgress({});
    setPromotions({});
    setAlbumResolutions({});
    promotionAttempts.current.clear();
    albumInvalidations.current.clear();
    processingRetry.reset();
    uploadMutation.mutate(batch, { onSuccess: setLastResult });
  }

  function retryBatch() {
    if (selection !== null) {
      uploadMutation.mutate(selection, { onSuccess: setLastResult });
    }
  }

  function reviewDuplicate(row: UploadQueueRow) {
    if (row.mediaUploadId === undefined) return;
    const item = selection?.items.find(
      (candidate) => candidate.idempotencyKey === row.key,
    );
    const previewUrl =
      item === undefined ? undefined : previews[item.idempotencyKey];
    if (scope === "generic") {
      const promotion = promotions[row.mediaUploadId];
      if (promotion === undefined || promotion.status !== "duplicate") return;
      setDuplicateError("");
      setDuplicateReview({
        kind: "generic",
        key: row.mediaUploadId,
        mediaUploadId: row.mediaUploadId,
        filename: row.filename,
        previewUrl,
        candidates: promotion.candidates,
      });
      return;
    }
    const hold = duplicateHolds.find(
      (candidate) => candidate.media_upload.id === row.mediaUploadId,
    );
    if (hold === undefined) return;
    const candidate = hold.candidates[0];
    setDuplicateError("");
    setDuplicateReview({
      kind: "album",
      key: hold.id,
      mediaUploadId: row.mediaUploadId,
      filename: row.filename,
      previewUrl,
      albumName: hold.target_album.name,
      requiresVisibilityConfirmation:
        candidate.visibility === "private" &&
        hold.target_album.visibility !== "private",
      candidates: hold.candidates,
    });
  }

  async function resolveDuplicate(
    choice: "use_existing" | "create_new",
    candidateId: string,
    confirmVisibilityWidening: boolean,
  ) {
    if (duplicateReview === null) return;
    setDuplicatePending(true);
    try {
      if (duplicateReview.kind === "generic") {
        const result = await createPhoto(familySlug, {
          ...photoInput(duplicateReview.mediaUploadId),
          duplicate_resolution: choice,
          ...(choice === "use_existing"
            ? { existing_photo_id: candidateId }
            : {
                disclosed_photo_ids: duplicateReview.candidates.map(
                  (candidate) => candidate.id,
                ),
              }),
        });
        if (
          result.outcome !== "cancelled" &&
          result.outcome !== "duplicate_detected"
        ) {
          setPromotions((current) => ({
            ...current,
            [duplicateReview.mediaUploadId]: {
              status: "ready",
              photoId: result.photo.id,
            },
          }));
          await invalidatePhotoContinuity(false);
        }
      } else {
        await resolveHold.mutateAsync({
          holdId: duplicateReview.key,
          resolution: choice,
          ...(choice === "use_existing"
            ? {
                existing_photo_id: candidateId,
                confirm_visibility_widening: confirmVisibilityWidening,
              }
            : {
                disclosed_photo_ids: duplicateReview.candidates.map(
                  (candidate) => candidate.id,
                ),
              }),
        });
        setAlbumResolutions((current) => ({
          ...current,
          [duplicateReview.mediaUploadId]: "ready",
        }));
        await invalidatePhotoContinuity(true);
      }
      setDuplicateReview(null);
      setDuplicateError("");
    } catch (error: unknown) {
      setDuplicateError(
        toAppError(error).message ||
          "The duplicate choice could not be saved. Please try again.",
      );
    } finally {
      setDuplicatePending(false);
    }
  }

  async function cancelDuplicate() {
    if (duplicateReview === null || duplicatePending) return;
    setDuplicatePending(true);
    try {
      if (duplicateReview.kind === "generic") {
        await createPhoto(familySlug, {
          ...photoInput(duplicateReview.mediaUploadId),
          duplicate_resolution: "cancel",
        });
        setPromotions((current) => ({
          ...current,
          [duplicateReview.mediaUploadId]: { status: "cancelled" },
        }));
      } else {
        await resolveHold.mutateAsync({
          holdId: duplicateReview.key,
          resolution: "cancel",
        });
        setAlbumResolutions((current) => ({
          ...current,
          [duplicateReview.mediaUploadId]: "cancelled",
        }));
      }
      setDuplicateReview(null);
      setDuplicateError("");
    } catch (error: unknown) {
      setDuplicateError(
        toAppError(error).message ||
          "The upload could not be cancelled. Please try again.",
      );
    } finally {
      setDuplicatePending(false);
    }
  }

  return (
    <main className="upload-page" aria-labelledby="media-upload-title">
      <PageHeader
        id="media-upload-title"
        eyebrow={scope === "album" ? albumName : "Family Space"}
        title="Add photographs"
        description={
          scope === "album"
            ? "New Photos will be added to this Album as they become ready."
            : "New Photos will be saved to your Family Space, ready to organise later."
        }
        actions={
          <Link className="upload-page__back" to={backPath}>
            {scope === "album" ? "Back to album" : "Back to Photos"}
          </Link>
        }
      />
      <div className={`upload-destination upload-destination--${scope}`}>
        <ImageIcon />
        <div>
          <b>
            {scope === "album"
              ? `Adding to ${albumName ?? "this Album"}`
              : "Not in an album"}
          </b>
          <p>
            {scope === "album"
              ? "Each created Photo gets this Album membership."
              : "No Album is created or guessed. You can add each Photo to one or more Albums later."}
          </p>
        </div>
      </div>
      <input
        ref={fileInput}
        id="media-file"
        aria-label="Photographs"
        className="ui-visually-hidden"
        name="media-file"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
        onChange={(event) => {
          beginUpload(Array.from(event.target.files ?? []));
          event.target.value = "";
        }}
      />
      <button
        type="button"
        className={`upload-dropzone${dragging ? " is-dragging" : ""}`}
        aria-describedby="upload-file-types"
        onClick={() => fileInput.current?.click()}
        onDragEnter={(event) => {
          event.preventDefault();
          setDragging(true);
        }}
        onDragOver={(event) => {
          event.preventDefault();
          event.dataTransfer.dropEffect = "copy";
          setDragging(true);
        }}
        onDragLeave={(event) => {
          if (
            !event.currentTarget.contains(event.relatedTarget as Node | null)
          ) {
            setDragging(false);
          }
        }}
        onDrop={(event) => {
          event.preventDefault();
          setDragging(false);
          beginUpload(Array.from(event.dataTransfer.files));
        }}
      >
        <span>
          <UploadIcon />
        </span>
        <b>Drop photographs here</b>
        <small>or choose files from your computer</small>
        <em>Choose photos</em>
      </button>
      <p id="upload-file-types" className="ui-visually-hidden">
        Accepted file types: JPEG, PNG, HEIC, HEIF, WebP and TIFF.
      </p>
      {selectionError !== "" && <p role="alert">{selectionError}</p>}
      {uploadMutation.isError && (
        <p role="alert">
          {toAppError(uploadMutation.error).message ||
            "The photographs could not be uploaded."}
        </p>
      )}
      {batchQuery.isError && lastResult !== null && (
        <p role="alert">Current processing status could not be loaded.</p>
      )}
      {rows.length > 0 && (
        <MediaUploadBatchStatus
          rows={rows}
          scope={scope}
          collapsed={collapsed}
          completionPanel={
            readyCount > 0 &&
            rows.every(
              (row) => row.tone !== "uploading" && row.tone !== "processing",
            ) ? (
              <section
                className="upload-handoff"
                aria-labelledby="upload-handoff-title"
              >
                <div>
                  <CheckIcon />
                  <span>
                    <b id="upload-handoff-title">Your photographs are ready</b>
                    <small>
                      {scope === "album"
                        ? `Ready Photos are now in ${albumName ?? "this Album"}.`
                        : "Ready Photos are now in your Family Space."}
                    </small>
                  </span>
                </div>
                <ButtonLink variant="primary" to={backPath}>
                  {scope === "album" ? "Back to album" : "View photographs"}
                  <ChevronRightIcon />
                </ButtonLink>
              </section>
            ) : undefined
          }
          onCollapsedChange={setCollapsed}
          onAction={(row) => {
            if (row.action === "retry-upload") retryBatch();
            if (
              row.action === "retry-processing" &&
              row.mediaUploadId !== undefined
            ) {
              processingRetry.mutate(row.mediaUploadId);
            }
            if (row.action === "review-duplicate") reviewDuplicate(row);
            if (
              row.action === "retry-photo" &&
              row.mediaUploadId !== undefined
            ) {
              promotionAttempts.current.add(row.mediaUploadId);
              void promoteReadyUpload(row.mediaUploadId);
            }
          }}
        />
      )}
      <UploadDuplicateDialog
        key={duplicateReview?.key ?? "closed"}
        familySlug={familySlug}
        review={duplicateReview}
        pending={duplicatePending}
        error={duplicateError}
        onCancel={() => void cancelDuplicate()}
        onContinue={(choice, candidateId, confirmVisibilityWidening) => {
          void resolveDuplicate(choice, candidateId, confirmVisibilityWidening);
        }}
      />
    </main>
  );
}

type BuildRowsInput = {
  selection: MediaUploadBatchInput | null;
  result: MediaUploadBatchResult | null;
  status: BatchStatus | undefined;
  progress: Record<string, number>;
  previews: Record<string, string>;
  promotions: Partial<Record<string, PromotionState>>;
  albumHolds: Array<{
    media_upload: { id: string };
    candidates: DuplicatePhotoCandidate[];
  }>;
  albumResolutions: Partial<Record<string, "ready" | "cancelled">>;
  scope: "generic" | "album";
  retryingId: string | null;
};

function buildRows(input: BuildRowsInput): UploadQueueRow[] {
  if (input.selection === null) return [];
  return input.selection.items.map(({ file, idempotencyKey }) => {
    const base = {
      key: idempotencyKey,
      filename: file.name,
      previewUrl: input.previews[idempotencyKey],
    };
    const outcome = input.result?.outcomes.find(
      (candidate) => candidate.item_key === idempotencyKey,
    );
    if (outcome === undefined) {
      const percent = input.progress[idempotencyKey] ?? 0;
      return {
        ...base,
        description:
          percent > 0 ? `Uploading · ${String(percent)}%` : "Preparing upload",
        badge: "Uploading",
        tone: "uploading",
        progress: percent,
      };
    }
    if (outcome.status === "failed") {
      return {
        ...base,
        description: outcome.message || "The upload needs another attempt",
        badge: "Needs attention",
        tone: "attention",
        progress: 100,
        action: "retry-upload",
      };
    }
    const mediaUploadId = outcome.upload.id;
    const server = input.status?.items.find(
      (item) => item.id === mediaUploadId,
    );
    if (server === undefined) {
      return {
        ...base,
        mediaUploadId,
        description: "Checking photograph",
        badge: "Processing",
        tone: "processing",
        progress: null,
      };
    }
    if (server.state === "degraded") {
      return {
        ...base,
        mediaUploadId,
        description:
          server.rejection_reason || "Processing needs another attempt",
        badge: "Needs attention",
        tone: "attention",
        progress: 100,
        action: "retry-processing",
        actionPending: input.retryingId === mediaUploadId,
      };
    }
    if (["quarantined", "abandoned"].includes(server.state)) {
      return {
        ...base,
        mediaUploadId,
        description: server.rejection_reason || stateDescription(server.state),
        badge: "Needs attention",
        tone: "attention",
        progress: 100,
      };
    }
    if (server.state !== "ready") {
      return {
        ...base,
        mediaUploadId,
        description: stateDescription(server.state),
        badge: server.state === "uploaded" ? "Uploading" : "Processing",
        tone: server.state === "uploaded" ? "uploading" : "processing",
        progress: null,
      };
    }
    if (input.scope === "album") {
      const resolution = input.albumResolutions[mediaUploadId];
      if (resolution === "cancelled") {
        return {
          ...base,
          mediaUploadId,
          description: "Upload cancelled",
          badge: "Cancelled",
          tone: "cancelled",
          progress: 100,
        };
      }
      const hold = input.albumHolds.find(
        (candidate) => candidate.media_upload.id === mediaUploadId,
      );
      if (hold !== undefined) {
        return {
          ...base,
          mediaUploadId,
          description: "Possible duplicate found",
          badge: "Needs attention",
          tone: "attention",
          progress: 100,
          action: "review-duplicate",
        };
      }
      return {
        ...base,
        mediaUploadId,
        description: "Ready · photograph created",
        badge: "Ready",
        tone: "ready",
        progress: 100,
      };
    }
    const promotion = input.promotions[mediaUploadId];
    if (promotion === undefined || promotion.status === "promoting") {
      return {
        ...base,
        mediaUploadId,
        description: "Creating Photo record",
        badge: "Processing",
        tone: "processing",
        progress: null,
      };
    }
    if (promotion.status === "duplicate") {
      return {
        ...base,
        mediaUploadId,
        description: "Possible duplicate found",
        badge: "Needs attention",
        tone: "attention",
        progress: 100,
        action: "review-duplicate",
      };
    }
    if (promotion.status === "failed") {
      return {
        ...base,
        mediaUploadId,
        description: promotion.message,
        badge: "Needs attention",
        tone: "attention",
        progress: 100,
        action: "retry-photo",
      };
    }
    if (promotion.status === "cancelled") {
      return {
        ...base,
        mediaUploadId,
        description: "Photo creation cancelled",
        badge: "Cancelled",
        tone: "cancelled",
        progress: 100,
      };
    }
    return {
      ...base,
      mediaUploadId,
      description: "Ready · photograph created",
      badge: "Ready",
      tone: "ready",
      progress: 100,
    };
  });
}

function photoInput(mediaUploadId: string): CreatePhotoInput {
  return {
    media_upload_id: mediaUploadId,
    visibility: "family_space",
    caption: null,
    description: null,
    archive_source_description: null,
    tags: [],
  };
}

function stateDescription(state: MediaUploadState): string {
  const descriptions: Record<MediaUploadState, string> = {
    initiated: "Preparing upload",
    uploaded: "Upload received",
    verifying: "Checking photograph",
    preserved: "Creating archive copy",
    processing: "Analysing photograph",
    ready: "Ready · photograph created",
    quarantined: "The photograph did not pass security checks",
    abandoned: "The upload expired before it completed",
    degraded: "Processing needs another attempt",
  };
  return descriptions[state];
}

function UploadIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
    >
      <path d="M12 16V4m0 0L7 9m5-5 5 5M5 20h14" />
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

function CheckIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
    >
      <circle cx="12" cy="12" r="9" />
      <path d="m8 12 2.5 2.5L16 9" />
    </svg>
  );
}

function ChevronRightIcon() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
    >
      <path d="m9 18 6-6-6-6" />
    </svg>
  );
}
