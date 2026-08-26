import { useState, type SyntheticEvent } from "react";

import type { Photo } from "@/features/photos/types/photo";

type Props = {
  currentPhotoId: string;
  photos: Photo[];
  pending: boolean;
  succeeded: boolean;
  failed: boolean;
  onSubmit: (candidatePhotoId: string) => void;
};

export function PhotoDuplicateFlagPanel({
  currentPhotoId,
  photos,
  pending,
  succeeded,
  failed,
  onSubmit,
}: Props) {
  const options = photos.filter((photo) => photo.id !== currentPhotoId);
  const [selected, setSelected] = useState("");

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (selected !== "") onSubmit(selected);
  }

  return (
    <section aria-labelledby="flag-duplicate-title">
      <h2 id="flag-duplicate-title">Suggest a possible duplicate</h2>
      <p>
        An Owner or Administrator will review the two Photos. Nothing is merged
        or deleted.
      </p>
      {options.length === 0 ? (
        <p>There are no other visible Photos to suggest.</p>
      ) : (
        <form onSubmit={submit}>
          <label htmlFor="duplicate-photo-choice">Other Photo</label>
          <select
            id="duplicate-photo-choice"
            required
            value={selected}
            onChange={(event) => {
              setSelected(event.target.value);
            }}
          >
            <option value="">Choose a Photo</option>
            {options.map((photo) => (
              <option key={photo.id} value={photo.id}>
                {photo.caption ?? photo.media_upload.client_filename}
              </option>
            ))}
          </select>
          <button type="submit" disabled={pending || selected === ""}>
            {pending ? "Sending…" : "Suggest duplicate"}
          </button>
        </form>
      )}
      {succeeded && (
        <p role="status">The possible duplicate was sent for review.</p>
      )}
      {failed && <p role="alert">The suggestion could not be saved.</p>}
    </section>
  );
}
