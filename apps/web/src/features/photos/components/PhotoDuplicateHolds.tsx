import { useState } from "react";

import {
  usePhotoDuplicateHoldsQuery,
  useResolvePhotoDuplicateHoldMutation,
} from "../hooks/usePhotoDuplicateHolds";

export function PhotoDuplicateHolds({ familySlug }: { familySlug: string }) {
  const holds = usePhotoDuplicateHoldsQuery(familySlug);
  const resolve = useResolvePhotoDuplicateHoldMutation(familySlug);
  const [selections, setSelections] = useState<Record<string, string>>({});
  const [wideningConfirmed, setWideningConfirmed] = useState<
    Record<string, boolean>
  >({});

  if (holds.isPending) return <p role="status">Checking duplicate uploads…</p>;
  if (holds.isError)
    return <p role="alert">Duplicate uploads could not be loaded.</p>;
  if (holds.data.length === 0) return null;

  return (
    <section aria-labelledby="duplicate-holds-title">
      <h2 id="duplicate-holds-title">Uploads waiting for your decision</h2>
      {holds.data.map((hold) => {
        const selected = selections[hold.id] || hold.candidates[0]?.id || "";
        const selectedPhoto = hold.candidates.find(
          (candidate) => candidate.id === selected,
        );
        const widensVisibility =
          selectedPhoto?.visibility === "private" &&
          hold.target_album.visibility !== "private";
        return (
          <fieldset key={hold.id}>
            <legend>
              {hold.media_upload.client_filename} for {hold.target_album.name}
            </legend>
            <p>This photograph already appears in the family archive.</p>
            {hold.candidates.map((candidate) => (
              <label key={candidate.id}>
                <input
                  type="radio"
                  name={`hold-${hold.id}`}
                  checked={selected === candidate.id}
                  onChange={() => {
                    setSelections({ ...selections, [hold.id]: candidate.id });
                  }}
                />
                {candidate.caption ?? candidate.client_filename}
              </label>
            ))}
            {widensVisibility && (
              <label>
                <input
                  type="checkbox"
                  checked={wideningConfirmed[hold.id] ?? false}
                  onChange={(event) => {
                    setWideningConfirmed({
                      ...wideningConfirmed,
                      [hold.id]: event.target.checked,
                    });
                  }}
                />
                I understand that adding this private Photo will widen its
                audience to this Album.
              </label>
            )}
            <button
              type="button"
              disabled={
                resolve.isPending ||
                selected === "" ||
                (widensVisibility && !wideningConfirmed[hold.id])
              }
              onClick={() => {
                resolve.mutate({
                  holdId: hold.id,
                  resolution: "use_existing",
                  existing_photo_id: selected,
                  confirm_visibility_widening:
                    wideningConfirmed[hold.id] ?? false,
                });
              }}
            >
              Use existing Photo
            </button>
            <button
              type="button"
              disabled={resolve.isPending}
              onClick={() => {
                resolve.mutate({
                  holdId: hold.id,
                  resolution: "create_new",
                  disclosed_photo_ids: hold.candidates.map(
                    (candidate) => candidate.id,
                  ),
                });
              }}
            >
              Create a new Photo
            </button>
            <button
              type="button"
              disabled={resolve.isPending}
              onClick={() => {
                resolve.mutate({ holdId: hold.id, resolution: "cancel" });
              }}
            >
              Cancel
            </button>
          </fieldset>
        );
      })}
    </section>
  );
}
