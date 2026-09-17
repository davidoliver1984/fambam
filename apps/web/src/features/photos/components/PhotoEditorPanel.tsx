import { useState } from "react";

import {
  useActivatePhotoVersionMutation,
  useApplyPhotoEditPreviewMutation,
  useCreatePhotoEditPreviewMutation,
  useCreateRestorePreviewMutation,
  useDiscardPhotoEditPreviewMutation,
  usePhotoEditPreviewDeliveryQuery,
  usePhotoVersionsQuery,
} from "../hooks/usePhotoEditor";
import {
  identityEditRecipe,
  type EditRecipe,
  type PhotoEditPreview,
  type PhotoFilter,
} from "../types/photoEditor";

type Props = { familySlug: string; photoId: string };

export function PhotoEditorPanel({ familySlug, photoId }: Props) {
  const versions = usePhotoVersionsQuery(familySlug, photoId);
  if (versions.isPending) return <p role="status">Loading Photo editor…</p>;
  if (versions.isError)
    return <p role="alert">Photo versions could not be loaded.</p>;

  const active = versions.data.versions.find(
    (version) => version.id === versions.data.active_photo_version_id,
  );
  return (
    <section aria-labelledby="photo-editor-title">
      <h2 id="photo-editor-title">Photo editor</h2>
      <p>
        Edits create a new version. The preserved original is never changed.
      </p>
      {versions.data.can_edit && (
        <PhotoEditorControls
          key={active?.id ?? "canonical"}
          familySlug={familySlug}
          photoId={photoId}
          initialRecipe={active?.edit_recipe ?? identityEditRecipe}
        />
      )}
      <h3>Presentation versions</h3>
      {versions.data.versions.length === 0 && <p>No edits yet.</p>}
      <ul>
        {versions.data.versions.map((version) => (
          <li key={version.id}>
            {version.restore === null ? "Edit" : "Restore"} ·{" "}
            {new Date(version.created_at).toLocaleString()}{" "}
            {version.id === versions.data.active_photo_version_id &&
              "(current)"}
            {versions.data.can_edit &&
              version.id !== versions.data.active_photo_version_id && (
                <VersionActivation
                  familySlug={familySlug}
                  photoId={photoId}
                  versionId={version.id}
                  label="Use this version"
                />
              )}
          </li>
        ))}
      </ul>
      {versions.data.can_edit &&
        versions.data.active_photo_version_id !== null && (
          <VersionActivation
            familySlug={familySlug}
            photoId={photoId}
            versionId={null}
            label="Revert to unedited Photo"
          />
        )}
    </section>
  );
}

function VersionActivation({
  familySlug,
  photoId,
  versionId,
  label,
}: Props & { versionId: string | null; label: string }) {
  const activate = useActivatePhotoVersionMutation(familySlug, photoId);
  return (
    <>
      <button
        type="button"
        disabled={activate.isPending}
        onClick={() => {
          activate.mutate(versionId);
        }}
      >
        {label}
      </button>
      {activate.isError && (
        <p role="alert">The version could not be activated.</p>
      )}
    </>
  );
}

function PhotoEditorControls({
  familySlug,
  photoId,
  initialRecipe,
}: Props & { initialRecipe: EditRecipe }) {
  const [draft, setDraft] = useState<EditRecipe>(() =>
    structuredClone(initialRecipe),
  );
  const editPreview = useCreatePhotoEditPreviewMutation(familySlug, photoId);
  const restorePreview = useCreateRestorePreviewMutation(familySlug, photoId);
  const apply = useApplyPhotoEditPreviewMutation(familySlug, photoId);
  const discard = useDiscardPhotoEditPreviewMutation(familySlug, photoId);
  const result = restorePreview.data ?? editPreview.data;
  const preview: PhotoEditPreview | null =
    result?.outcome === "preview_ready" ? result : null;
  const delivery = usePhotoEditPreviewDeliveryQuery(
    familySlug,
    photoId,
    preview?.id ?? null,
  );
  const pending =
    editPreview.isPending ||
    restorePreview.isPending ||
    apply.isPending ||
    discard.isPending;
  const hasError =
    editPreview.isError ||
    restorePreview.isError ||
    apply.isError ||
    discard.isError;
  const clearPreview = () => {
    editPreview.reset();
    restorePreview.reset();
  };
  const setAdjustment = (
    field: keyof EditRecipe["adjustments"],
    value: number,
  ) => {
    setDraft((current) => ({
      ...current,
      adjustments: { ...current.adjustments, [field]: value },
    }));
  };

  return (
    <div className="photo-editor-controls">
      <fieldset disabled={pending || preview !== null}>
        <legend>Adjust presentation</legend>
        <label htmlFor="photo-rotate">Rotate</label>
        <select
          id="photo-rotate"
          value={draft.rotate_degrees}
          onChange={(event) => {
            setDraft((current) => ({
              ...current,
              rotate_degrees: Number(event.target.value) as 0 | 90 | 180 | 270,
            }));
          }}
        >
          {[0, 90, 180, 270].map((degrees) => (
            <option key={degrees} value={degrees}>
              {degrees}°
            </option>
          ))}
        </select>
        <label htmlFor="photo-straighten">Straighten (degrees)</label>
        <input
          id="photo-straighten"
          type="number"
          min={-15}
          max={15}
          step={0.1}
          value={draft.straighten_degrees}
          onChange={(event) => {
            setDraft((current) => ({
              ...current,
              straighten_degrees: Number(event.target.value),
            }));
          }}
        />
        <label>
          <input
            type="checkbox"
            checked={draft.flip_horizontal}
            onChange={(event) => {
              setDraft((current) => ({
                ...current,
                flip_horizontal: event.target.checked,
              }));
            }}
          />{" "}
          Flip horizontally
        </label>
        <label>
          <input
            type="checkbox"
            checked={draft.flip_vertical}
            onChange={(event) => {
              setDraft((current) => ({
                ...current,
                flip_vertical: event.target.checked,
              }));
            }}
          />{" "}
          Flip vertically
        </label>
        <label>
          <input
            type="checkbox"
            checked={draft.crop !== null}
            onChange={(event) => {
              setDraft((current) => ({
                ...current,
                crop: event.target.checked
                  ? { x: 0, y: 0, width: 1, height: 1 }
                  : null,
              }));
            }}
          />{" "}
          Crop
        </label>
        {draft.crop !== null &&
          (["x", "y", "width", "height"] as const).map((field) => (
            <label key={field}>
              Crop {field}
              <input
                type="number"
                min={0}
                max={1}
                step={0.01}
                value={draft.crop?.[field] ?? 0}
                onChange={(event) => {
                  setDraft((current) => ({
                    ...current,
                    crop: current.crop && {
                      ...current.crop,
                      [field]: Number(event.target.value),
                    },
                  }));
                }}
              />
            </label>
          ))}
        {(["brightness", "contrast", "saturation", "warmth"] as const).map(
          (field) => (
            <label key={field}>
              {field}
              <input
                type="range"
                min={-100}
                max={100}
                value={draft.adjustments[field]}
                onChange={(event) => {
                  setAdjustment(field, Number(event.target.value));
                }}
              />{" "}
              <output>{draft.adjustments[field]}</output>
            </label>
          ),
        )}
        <label htmlFor="photo-filter">Filter</label>
        <select
          id="photo-filter"
          value={draft.filter.name ?? ""}
          onChange={(event) => {
            const name = event.target.value as PhotoFilter | "";
            setDraft((current) => ({
              ...current,
              filter: {
                name: name === "" ? null : name,
                intensity: name === "" ? 0 : current.filter.intensity,
              },
            }));
          }}
        >
          <option value="">None</option>
          <option value="black_and_white">Black and white</option>
          <option value="sepia">Sepia</option>
          <option value="warm">Warm</option>
          <option value="cool">Cool</option>
          <option value="sharpen">Sharpen</option>
        </select>
        {draft.filter.name !== null && (
          <label>
            Filter intensity
            <input
              type="range"
              min={0}
              max={100}
              value={draft.filter.intensity}
              onChange={(event) => {
                setDraft((current) => ({
                  ...current,
                  filter: {
                    ...current.filter,
                    intensity: Number(event.target.value),
                  },
                }));
              }}
            />{" "}
            <output>{draft.filter.intensity}</output>
          </label>
        )}
        <button
          type="button"
          onClick={() => {
            restorePreview.reset();
            editPreview.mutate(draft);
          }}
        >
          Preview edit
        </button>
        <button
          type="button"
          onClick={() => {
            editPreview.reset();
            restorePreview.mutate();
          }}
        >
          Preview Restore
        </button>
      </fieldset>
      {result?.outcome === "no_improvement_found" && (
        <p role="status">
          Restore found no meaningful improvement; your Photo is unchanged.
        </p>
      )}
      {preview !== null && (
        <div>
          <h3>Preview {preview.restore === null ? "edit" : "Restore"}</h3>
          {delivery.isPending && <p role="status">Loading preview…</p>}
          {delivery.isError && <p role="alert">This preview is unavailable.</p>}
          {delivery.isSuccess && (
            <img src={delivery.data.url} alt="Proposed edit" />
          )}
          <p>The preserved original and current version are still unchanged.</p>
          <button
            type="button"
            disabled={pending || !delivery.isSuccess}
            onClick={() => {
              apply.mutate(preview.id, { onSuccess: clearPreview });
            }}
          >
            Apply version
          </button>
          <button
            type="button"
            disabled={pending}
            onClick={() => {
              discard.mutate(preview.id, { onSuccess: clearPreview });
            }}
          >
            Keep current Photo
          </button>
        </div>
      )}
      {hasError && (
        <p role="alert">The Photo edit could not be completed. Please retry.</p>
      )}
    </div>
  );
}
