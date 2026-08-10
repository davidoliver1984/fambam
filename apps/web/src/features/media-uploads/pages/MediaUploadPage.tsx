import { type SyntheticEvent, useRef, useState } from "react";
import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";

import { useMediaUploadMutation } from "../hooks/useMediaUploadMutation";

export function MediaUploadPage() {
  const { familySlug = "" } = useParams();
  const uploadMutation = useMediaUploadMutation(familySlug);
  const [file, setFile] = useState<File | null>(null);
  const idempotencyKey = useRef<string | null>(null);

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (file === null) {
      return;
    }

    idempotencyKey.current ??= crypto.randomUUID();
    uploadMutation.mutate({ file, idempotencyKey: idempotencyKey.current });
  }

  function selectFile(nextFile: File | null) {
    setFile(nextFile);
    idempotencyKey.current = null;
    uploadMutation.reset();
  }

  return (
    <main className="auth media-upload" aria-labelledby="media-upload-title">
      <p className="eyebrow">fambam</p>
      <h1 id="media-upload-title">Upload a family photograph</h1>
      <p>
        The original uploads directly to private object storage and will be
        checked before it can be used.
      </p>
      <form onSubmit={submit}>
        <label htmlFor="media-file">Photograph</label>
        <input
          id="media-file"
          name="media-file"
          type="file"
          accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
          onChange={(event) => {
            selectFile(event.target.files?.[0] ?? null);
          }}
          required
        />
        <button
          type="submit"
          disabled={file === null || uploadMutation.isPending}
        >
          {uploadMutation.isPending ? "Uploading…" : "Upload photograph"}
        </button>
      </form>
      {uploadMutation.isError && (
        <p role="alert">
          {toAppError(uploadMutation.error).message ||
            "The photograph could not be uploaded."}
        </p>
      )}
      {uploadMutation.isSuccess && (
        <p role="status">
          {uploadMutation.data.client_filename} arrived safely and is waiting
          for verification.
        </p>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
