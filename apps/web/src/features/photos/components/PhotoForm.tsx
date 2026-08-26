import { type SyntheticEvent, useState } from "react";

import type {
  CreatePhotoInput,
  CreatePhotoResult,
  DuplicatePhotoCandidate,
  Photo,
  PhotoVisibility,
  PromotableMediaUpload,
  UpdatePhotoInput,
} from "../types/photo";
import { splitTags } from "../validation/tagInput";

type PhotoFormProps = {
  photo?: Photo;
  promotableUploads?: PromotableMediaUpload[];
  pending: boolean;
  onSubmit: (
    input: CreatePhotoInput | UpdatePhotoInput,
  ) => Promise<CreatePhotoResult | Photo>;
};

export function PhotoForm({
  photo,
  promotableUploads = [],
  pending,
  onSubmit,
}: PhotoFormProps) {
  const [mediaUploadId, setMediaUploadId] = useState("");
  const [visibility, setVisibility] = useState<PhotoVisibility>(
    photo?.visibility ?? "family_space",
  );
  const [caption, setCaption] = useState(photo?.caption ?? "");
  const [description, setDescription] = useState(photo?.description ?? "");
  const [archiveSource, setArchiveSource] = useState(
    photo?.archive_source_description ?? "",
  );
  const [tags, setTags] = useState(
    photo?.tags.map((tag) => tag.label).join(", ") ?? "",
  );
  const [message, setMessage] = useState("");
  const [duplicateCandidates, setDuplicateCandidates] = useState<
    DuplicatePhotoCandidate[]
  >([]);
  const [selectedCandidateId, setSelectedCandidateId] = useState("");

  function createInput(
    duplicateResolution?: CreatePhotoInput["duplicate_resolution"],
  ): CreatePhotoInput {
    return {
      visibility,
      caption: emptyToNull(caption),
      description: emptyToNull(description),
      archive_source_description: emptyToNull(archiveSource),
      media_upload_id: mediaUploadId.trim(),
      tags: splitTags(tags),
      ...(duplicateResolution === undefined
        ? {}
        : { duplicate_resolution: duplicateResolution }),
      ...(duplicateResolution === "use_existing"
        ? { existing_photo_id: selectedCandidateId }
        : {}),
      ...(duplicateResolution === "create_new"
        ? {
            disclosed_photo_ids: duplicateCandidates.map(
              (candidate) => candidate.id,
            ),
          }
        : {}),
    };
  }

  function clearCreationForm() {
    setMediaUploadId("");
    setCaption("");
    setDescription("");
    setArchiveSource("");
    setTags("");
    setDuplicateCandidates([]);
    setSelectedCandidateId("");
  }

  function handleCreationResult(result: CreatePhotoResult) {
    if (result.outcome === "duplicate_detected") {
      setDuplicateCandidates(result.candidates);
      setSelectedCandidateId(result.candidates[0]?.id ?? "");
      setMessage("Choose how to handle the matching photograph.");
      return;
    }
    clearCreationForm();
    setMessage(
      result.outcome === "existing_photo"
        ? "The existing Photo was used."
        : result.outcome === "cancelled"
          ? "Photo creation cancelled."
          : "Photo record created.",
    );
  }

  async function resolveDuplicate(
    resolution: NonNullable<CreatePhotoInput["duplicate_resolution"]>,
  ) {
    setMessage("");
    try {
      const result = await onSubmit(createInput(resolution));
      if ("outcome" in result) handleCreationResult(result);
    } catch {
      setMessage("The duplicate decision could not be saved. Try again.");
    }
  }

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    const content = {
      visibility,
      caption: emptyToNull(caption),
      description: emptyToNull(description),
      archive_source_description: emptyToNull(archiveSource),
    };

    try {
      if (photo === undefined) {
        const result = await onSubmit(createInput());
        if ("outcome" in result) handleCreationResult(result);
      } else {
        await onSubmit(content);
        setMessage("Photo updated.");
      }
    } catch {
      setMessage(
        "The Photo could not be saved. Check the details and try again.",
      );
    }
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      {photo === undefined && (
        <>
          <label htmlFor="photo-media-upload">Ready upload</label>
          <select
            id="photo-media-upload"
            value={mediaUploadId}
            onChange={(event) => {
              setMediaUploadId(event.target.value);
            }}
            required
          >
            <option value="">Choose a ready upload</option>
            {promotableUploads.map((upload) => (
              <option key={upload.id} value={upload.id}>
                {uploadLabel(upload)}
              </option>
            ))}
          </select>
          <p>
            Choose a completed upload to promote. Members see only their own
            eligible uploads.
          </p>
        </>
      )}
      {photo === undefined && duplicateCandidates.length > 0 && (
        <fieldset>
          <legend>Matching Photos already in the archive</legend>
          {duplicateCandidates.map((candidate) => (
            <label key={candidate.id}>
              <input
                type="radio"
                name="duplicate-photo"
                value={candidate.id}
                checked={selectedCandidateId === candidate.id}
                onChange={() => {
                  setSelectedCandidateId(candidate.id);
                }}
              />
              {candidate.caption ?? candidate.client_filename}
            </label>
          ))}
          <button
            type="button"
            disabled={pending || selectedCandidateId === ""}
            onClick={() => {
              void resolveDuplicate("use_existing");
            }}
          >
            Use existing Photo
          </button>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              void resolveDuplicate("create_new");
            }}
          >
            Create a new Photo
          </button>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              void resolveDuplicate("cancel");
            }}
          >
            Cancel
          </button>
        </fieldset>
      )}
      <label htmlFor={`photo-caption-${photo?.id ?? "new"}`}>Caption</label>
      <input
        id={`photo-caption-${photo?.id ?? "new"}`}
        value={caption}
        onChange={(event) => {
          setCaption(event.target.value);
        }}
        maxLength={255}
      />
      <label htmlFor={`photo-description-${photo?.id ?? "new"}`}>
        Description
      </label>
      <textarea
        id={`photo-description-${photo?.id ?? "new"}`}
        value={description}
        onChange={(event) => {
          setDescription(event.target.value);
        }}
        rows={4}
      />
      <label htmlFor={`photo-source-${photo?.id ?? "new"}`}>
        Archive source
      </label>
      <input
        id={`photo-source-${photo?.id ?? "new"}`}
        value={archiveSource}
        onChange={(event) => {
          setArchiveSource(event.target.value);
        }}
        placeholder="Box labelled Spain"
      />
      <label htmlFor={`photo-visibility-${photo?.id ?? "new"}`}>
        Visibility
      </label>
      <select
        id={`photo-visibility-${photo?.id ?? "new"}`}
        value={visibility}
        onChange={(event) => {
          setVisibility(
            event.target.value === "private" ? "private" : "family_space",
          );
        }}
      >
        <option value="family_space">Family Space</option>
        <option value="private">Private</option>
      </select>
      {photo === undefined && (
        <>
          <label htmlFor="photo-tags">Tags</label>
          <input
            id="photo-tags"
            value={tags}
            onChange={(event) => {
              setTags(event.target.value);
            }}
            placeholder="Holiday, Seaside"
          />
        </>
      )}
      {(photo !== undefined || duplicateCandidates.length === 0) && (
        <button
          type="submit"
          disabled={pending || (photo === undefined && mediaUploadId === "")}
        >
          {pending
            ? "Saving…"
            : photo === undefined
              ? "Create Photo"
              : "Save changes"}
        </button>
      )}
      {message !== "" && (
        <p
          className="form-message"
          role={message.includes("could not") ? "alert" : "status"}
        >
          {message}
        </p>
      )}
    </form>
  );
}

function emptyToNull(value: string): string | null {
  const trimmed = value.trim();
  return trimmed === "" ? null : trimmed;
}

function uploadLabel(upload: PromotableMediaUpload): string {
  if (upload.uploaded_at === null) return upload.client_filename;
  return `${upload.client_filename} — ${new Intl.DateTimeFormat(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  }).format(new Date(upload.uploaded_at))}`;
}
