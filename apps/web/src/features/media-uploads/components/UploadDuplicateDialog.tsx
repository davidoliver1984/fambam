import { useState } from "react";

import { Button, Dialog } from "@/components/ui";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotoQuery } from "@/features/photos/hooks/usePhotoQueries";
import type { DuplicatePhotoCandidate } from "@/features/photos/types/photo";

export type UploadDuplicateReview = {
  kind: "generic" | "album";
  key: string;
  mediaUploadId: string;
  filename: string;
  previewUrl?: string;
  albumName?: string;
  requiresVisibilityConfirmation?: boolean;
  candidates: DuplicatePhotoCandidate[];
};

type UploadDuplicateDialogProps = {
  familySlug: string;
  review: UploadDuplicateReview | null;
  pending: boolean;
  error: string;
  onCancel: () => void;
  onContinue: (
    choice: "use_existing" | "create_new",
    candidateId: string,
    confirmVisibilityWidening: boolean,
  ) => void;
};

export function UploadDuplicateDialog({
  familySlug,
  review,
  pending,
  error,
  onCancel,
  onContinue,
}: UploadDuplicateDialogProps) {
  const [choice, setChoice] = useState<"use_existing" | "create_new">(
    "use_existing",
  );
  const [visibilityConfirmed, setVisibilityConfirmed] = useState(false);
  const candidate = review?.candidates[0];
  const candidatePhoto = usePhotoQuery(familySlug, candidate?.id ?? "");

  const albumName = review?.albumName;
  const generic = review?.kind === "generic";

  return (
    <Dialog
      open={review !== null}
      title="This photo is already in Fambam"
      eyebrow="Duplicate photo found"
      description="Choose what should happen with this upload, then continue."
      className="upload-duplicate-dialog"
      pending={pending}
      showCloseButton={false}
      onClose={onCancel}
    >
      {review !== null && candidate !== undefined && (
        <div className="upload-duplicate-comparison">
          <article>
            <p>Existing Photo</p>
            {candidatePhoto.data === undefined ? (
              <div className="upload-duplicate-image-state" role="status">
                Loading photograph…
              </div>
            ) : (
              <PhotoPresentationImage
                familySlug={familySlug}
                photoId={candidatePhoto.data.id}
                mediaUploadId={candidatePhoto.data.media_upload.id}
                fallbackTransform="card"
                alt={`Existing photograph: ${candidate.caption ?? candidate.client_filename}`}
              />
            )}
            <b>{candidate.caption ?? candidate.client_filename}</b>
            <small>Already in Family Space</small>
          </article>
          <article>
            <p>This upload</p>
            {review.previewUrl === undefined ? (
              <div
                className="upload-duplicate-image-state"
                aria-hidden="true"
              />
            ) : (
              <img
                src={review.previewUrl}
                alt={`Uploaded file ${review.filename}`}
              />
            )}
            <b>{review.filename}</b>
            <small>Exact file match</small>
          </article>
        </div>
      )}
      <fieldset className="upload-duplicate-choices">
        <legend>What would you like to do?</legend>
        <label className={choice === "use_existing" ? "selected" : ""}>
          <input
            type="radio"
            name="duplicate"
            aria-label="Use the existing Photo"
            checked={choice === "use_existing"}
            onChange={() => {
              setChoice("use_existing");
            }}
          />
          <span>
            <b>Use the existing Photo</b>
            <small>
              {generic
                ? "Keep the existing Photo. No Album membership or duplicate Photo is created."
                : `Add it to ${albumName ?? "this Album"}. No duplicate Photo is created.`}
            </small>
          </span>
        </label>
        <label className={choice === "create_new" ? "selected" : ""}>
          <input
            type="radio"
            name="duplicate"
            aria-label="Create a separate Photo"
            checked={choice === "create_new"}
            onChange={() => {
              setChoice("create_new");
            }}
          />
          <span>
            <b>Create a separate Photo</b>
            <small>
              Keep this upload as another Photo, even though the file is
              identical.
            </small>
          </span>
        </label>
        <p>
          {choice === "use_existing"
            ? generic
              ? "The existing Photo stays in the Family Space with its current Album memberships unchanged."
              : `The existing Photo will also appear in ${albumName ?? "this Album"}. A fresh Album conversation starts there.`
            : "A new Photo record will be created. The existing Photo remains unchanged."}
        </p>
        {choice === "use_existing" &&
          review?.requiresVisibilityConfirmation === true && (
            <label className="upload-visibility-confirmation">
              <input
                type="checkbox"
                checked={visibilityConfirmed}
                onChange={(event) => {
                  setVisibilityConfirmed(event.target.checked);
                }}
              />
              <span>
                I understand that using this private Photo will make it visible
                to this Album’s audience.
              </span>
            </label>
          )}
      </fieldset>
      {error !== "" && (
        <p className="upload-duplicate-error" role="alert">
          {error}
        </p>
      )}
      <footer className="upload-duplicate-footer">
        <Button variant="secondary" disabled={pending} onClick={onCancel}>
          Cancel
        </Button>
        <Button
          variant="primary"
          disabled={
            pending ||
            candidate === undefined ||
            (choice === "use_existing" &&
              review?.requiresVisibilityConfirmation === true &&
              !visibilityConfirmed)
          }
          data-autofocus
          onClick={() => {
            if (candidate !== undefined) {
              onContinue(choice, candidate.id, visibilityConfirmed);
            }
          }}
        >
          {pending ? "Continuing…" : "Continue uploading"}
        </Button>
      </footer>
    </Dialog>
  );
}
