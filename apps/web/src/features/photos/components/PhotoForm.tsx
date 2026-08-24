import { type SyntheticEvent, useState } from "react";

import type {
  CreatePhotoInput,
  Photo,
  PhotoVisibility,
  UpdatePhotoInput,
} from "../types/photo";
import { splitTags } from "../validation/tagInput";

type PhotoFormProps = {
  photo?: Photo;
  pending: boolean;
  onSubmit: (input: CreatePhotoInput | UpdatePhotoInput) => Promise<unknown>;
};

export function PhotoForm({ photo, pending, onSubmit }: PhotoFormProps) {
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
        await onSubmit({
          ...content,
          media_upload_id: mediaUploadId.trim(),
          tags: splitTags(tags),
        });
        setMediaUploadId("");
        setCaption("");
        setDescription("");
        setArchiveSource("");
        setTags("");
      } else {
        await onSubmit(content);
      }
      setMessage(
        photo === undefined ? "Photo record created." : "Photo updated.",
      );
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
          <label htmlFor="photo-media-upload">Ready MediaUpload ID</label>
          <input
            id="photo-media-upload"
            value={mediaUploadId}
            onChange={(event) => {
              setMediaUploadId(event.target.value);
            }}
            minLength={26}
            maxLength={26}
            required
          />
          <p>
            Use the identifier from a completed upload. Members may promote only
            their own uploads.
          </p>
        </>
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
      <button type="submit" disabled={pending}>
        {pending
          ? "Saving…"
          : photo === undefined
            ? "Create Photo"
            : "Save changes"}
      </button>
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
