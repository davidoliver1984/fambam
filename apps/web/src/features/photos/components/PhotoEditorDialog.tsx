import { useState } from "react";

import { Button, Dialog } from "@/components/ui";

import {
  useApplyPhotoEditPreviewMutation,
  useCreatePhotoEditPreviewMutation,
} from "../hooks/usePhotoEditor";
import {
  identityEditRecipe,
  type EditRecipe,
  type PhotoFilter,
} from "../types/photoEditor";
import { PhotoPresentationImage } from "./PhotoPresentationImage";
import "./PhotoEditorDialog.css";

type EditorTab = "adjust" | "filters";

const filters: Array<{ id: PhotoFilter; label: string; className: string }> = [
  { id: "black_and_white", label: "Black & White", className: "filter-bw" },
  { id: "sepia", label: "Sepia", className: "filter-sepia" },
  { id: "warm", label: "Warm", className: "filter-warm" },
  { id: "cool", label: "Cool", className: "filter-cool" },
  { id: "sharpen", label: "Sharpen", className: "filter-sharpen" },
];

function ToolGlyph({
  kind,
}: {
  kind:
    | "crop"
    | "left"
    | "right"
    | "adjust"
    | "flip-x"
    | "flip-y"
    | "reset"
    | "save"
    | "shield";
}) {
  const paths: Record<typeof kind, string> = {
    crop: "M6 2v14a2 2 0 0 0 2 2h14M2 6h14a2 2 0 0 1 2 2v14",
    left: "M3 12a9 9 0 1 0 3-6.7M3 3v6h6",
    right: "M21 12a9 9 0 1 1-3-6.7M21 3v6h-6",
    adjust:
      "M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6",
    "flip-x": "M12 3v18M8 7 3 12l5 5M16 7l5 5-5 5",
    "flip-y": "M3 12h18M7 8l5-5 5 5M7 16l5 5 5-5",
    reset: "M3 12a9 9 0 1 0 3-6.7M3 3v6h6",
    save: "m5 12 4 4L19 6",
    shield: "M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Zm-3-10 2 2 4-4",
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d={paths[kind]} />
    </svg>
  );
}

export function PhotoEditorDialog({
  open,
  familySlug,
  photoId,
  mediaUploadId,
  title,
  onClose,
}: {
  open: boolean;
  familySlug: string;
  photoId: string;
  mediaUploadId: string;
  title: string;
  onClose: () => void;
}) {
  const [tab, setTab] = useState<EditorTab>("adjust");
  const [draft, setDraft] = useState<EditRecipe>(() =>
    structuredClone(identityEditRecipe),
  );
  const preview = useCreatePhotoEditPreviewMutation(familySlug, photoId);
  const apply = useApplyPhotoEditPreviewMutation(familySlug, photoId);
  const pending = preview.isPending || apply.isPending;
  const reset = () => {
    setTab("adjust");
    setDraft(structuredClone(identityEditRecipe));
    preview.reset();
    apply.reset();
  };
  const updateAdjustment = (
    field: keyof EditRecipe["adjustments"],
    value: number,
  ) => {
    setDraft((current) => ({
      ...current,
      adjustments: { ...current.adjustments, [field]: value },
    }));
  };
  const filterName = draft.filter.name;
  const intensity = draft.filter.intensity;
  const warmthCss =
    draft.adjustments.warmth >= 0
      ? `sepia(${String(Math.round(draft.adjustments.warmth * 0.12))}%)`
      : `hue-rotate(${String(Math.round(draft.adjustments.warmth * 0.2))}deg)`;
  const filterCss =
    filterName === "black_and_white"
      ? `grayscale(${String(intensity)}%)`
      : filterName === "sepia"
        ? `sepia(${String(intensity)}%)`
        : filterName === "warm"
          ? `sepia(${String(Math.round(intensity * 0.22))}%) saturate(${String(100 + Math.round(intensity * 0.28))}%)`
          : filterName === "cool"
            ? `hue-rotate(${String(Math.round(intensity * 0.25))}deg) saturate(${String(100 + Math.round(intensity * 0.12))}%)`
            : filterName === "sharpen"
              ? `contrast(${String(100 + Math.round(intensity * 0.2))}%) saturate(${String(100 + Math.round(intensity * 0.08))}%)`
              : "";
  const imageStyle = {
    filter: `brightness(${String(100 + draft.adjustments.brightness)}%) contrast(${String(100 + draft.adjustments.contrast)}%) saturate(${String(100 + draft.adjustments.saturation)}%) ${warmthCss} ${filterCss}`,
    transform: `rotate(${String(draft.rotate_degrees + draft.straighten_degrees)}deg) scaleX(${draft.flip_horizontal ? "-1" : "1"}) scaleY(${draft.flip_vertical ? "-1" : "1"})`,
  };
  const close = () => {
    onClose();
    reset();
  };
  const slider = (
    label: string,
    value: number,
    setValue: (value: number) => void,
    min: number,
    max: number,
    suffix = "",
    outputOffset = 0,
  ) => (
    <label className="editor-slider">
      <span>
        <b>{label}</b>
        <output>
          {value + outputOffset}
          {suffix}
        </output>
      </span>
      <input
        aria-label={label}
        type="range"
        min={min}
        max={max}
        value={value}
        onChange={(event) => {
          setValue(Number(event.target.value));
        }}
      />
    </label>
  );

  return (
    <Dialog
      open={open}
      title={`Edit “${title}”`}
      eyebrow="Photo editor"
      className="photo-editor-dialog"
      pending={pending}
      onClose={close}
    >
      <div className="photo-editor-body">
        <section className="editor-workspace" aria-label="Photo preview">
          <div className="editor-canvas">
            <div
              className={`editor-image${draft.crop === null ? "" : " crop-active"}`}
              style={imageStyle}
            >
              <PhotoPresentationImage
                familySlug={familySlug}
                photoId={photoId}
                mediaUploadId={mediaUploadId}
                fallbackTransform="display"
                alt={title}
              />
            </div>
            {draft.crop !== null && (
              <div className="crop-grid" aria-hidden="true">
                <i />
                <i />
                <i />
                <i />
              </div>
            )}
          </div>
          <div className="editor-tools" role="toolbar" aria-label="Photo tools">
            <button
              type="button"
              className={draft.crop === null ? "" : "active"}
              aria-pressed={draft.crop !== null}
              onClick={() => {
                setDraft((current) => ({
                  ...current,
                  crop:
                    current.crop === null
                      ? { x: 0, y: 0, width: 1, height: 1 }
                      : null,
                }));
              }}
            >
              <ToolGlyph kind="crop" />
              <span>Crop</span>
            </button>
            <button
              type="button"
              onClick={() => {
                setDraft((current) => ({
                  ...current,
                  rotate_degrees: ((current.rotate_degrees + 270) %
                    360) as EditRecipe["rotate_degrees"],
                }));
              }}
            >
              <ToolGlyph kind="left" />
              <span>Rotate left</span>
            </button>
            <button
              type="button"
              onClick={() => {
                setDraft((current) => ({
                  ...current,
                  rotate_degrees: ((current.rotate_degrees + 90) %
                    360) as EditRecipe["rotate_degrees"],
                }));
              }}
            >
              <ToolGlyph kind="right" />
              <span>Rotate right</span>
            </button>
            <button
              type="button"
              className={tab === "adjust" ? "active" : ""}
              onClick={() => {
                setTab("adjust");
              }}
            >
              <ToolGlyph kind="adjust" />
              <span>Straighten</span>
            </button>
            <button
              type="button"
              onClick={() => {
                setDraft((current) => ({
                  ...current,
                  flip_horizontal: !current.flip_horizontal,
                }));
              }}
            >
              <ToolGlyph kind="flip-x" />
              <span>Flip horizontal</span>
            </button>
            <button
              type="button"
              onClick={() => {
                setDraft((current) => ({
                  ...current,
                  flip_vertical: !current.flip_vertical,
                }));
              }}
            >
              <ToolGlyph kind="flip-y" />
              <span>Flip vertical</span>
            </button>
          </div>
        </section>
        <aside className="editor-panel">
          <div
            className="editor-tabs"
            role="tablist"
            aria-label="Editor controls"
          >
            <button
              type="button"
              role="tab"
              aria-selected={tab === "adjust"}
              className={tab === "adjust" ? "active" : ""}
              onClick={() => {
                setTab("adjust");
              }}
            >
              Adjust
            </button>
            <button
              type="button"
              role="tab"
              aria-selected={tab === "filters"}
              className={tab === "filters" ? "active" : ""}
              onClick={() => {
                setTab("filters");
              }}
            >
              Filters
            </button>
          </div>
          {tab === "adjust" ? (
            <div className="editor-controls" role="tabpanel">
              <p className="editor-panel-intro">
                Make small, natural corrections to the photograph.
              </p>
              {slider(
                "Brightness",
                draft.adjustments.brightness,
                (value) => {
                  updateAdjustment("brightness", value);
                },
                -30,
                30,
                "%",
                100,
              )}
              {slider(
                "Contrast",
                draft.adjustments.contrast,
                (value) => {
                  updateAdjustment("contrast", value);
                },
                -30,
                30,
                "%",
                100,
              )}
              {slider(
                "Saturation",
                draft.adjustments.saturation,
                (value) => {
                  updateAdjustment("saturation", value);
                },
                -100,
                50,
                "%",
                100,
              )}
              {slider(
                "Warmth",
                draft.adjustments.warmth,
                (value) => {
                  updateAdjustment("warmth", value);
                },
                -50,
                50,
              )}
              {slider(
                "Straighten",
                draft.straighten_degrees,
                (value) => {
                  setDraft((current) => ({
                    ...current,
                    straighten_degrees: value,
                  }));
                },
                -10,
                10,
                "°",
              )}
            </div>
          ) : (
            <div className="editor-filter-panel" role="tabpanel">
              <p className="editor-panel-intro">
                Five quiet finishes, made for family photographs.
              </p>
              <div className="filter-options">
                {filters.map((filter) => (
                  <button
                    type="button"
                    key={filter.id}
                    className={filterName === filter.id ? "active" : ""}
                    aria-pressed={filterName === filter.id}
                    onClick={() => {
                      setDraft((current) => ({
                        ...current,
                        filter: {
                          name: filter.id,
                          intensity: current.filter.intensity || 35,
                        },
                      }));
                    }}
                  >
                    <span className={filter.className}>
                      <PhotoPresentationImage
                        familySlug={familySlug}
                        photoId={photoId}
                        mediaUploadId={mediaUploadId}
                        fallbackTransform="thumbnail"
                        alt=""
                      />
                    </span>
                    <b>{filter.label}</b>
                  </button>
                ))}
              </div>
              {filterName !== null && (
                <div className="filter-intensity">
                  {slider(
                    `${filters.find((filter) => filter.id === filterName)?.label ?? "Filter"} intensity`,
                    intensity,
                    (value) => {
                      setDraft((current) => ({
                        ...current,
                        filter: { ...current.filter, intensity: value },
                      }));
                    },
                    0,
                    100,
                    "%",
                  )}
                </div>
              )}
            </div>
          )}
        </aside>
      </div>
      {(preview.isError || apply.isError) && (
        <p className="photo-editor-error" role="alert">
          The Photo edit could not be completed. Please retry.
        </p>
      )}
      <footer className="photo-editor-footer">
        <div className="editor-reassurance">
          <ToolGlyph kind="shield" />
          <span>
            <b>Your original photo will always be preserved.</b>
            <small>Your edits will be saved as a new version.</small>
          </span>
        </div>
        <div className="editor-actions">
          <Button
            type="button"
            variant="secondary"
            className="editor-cancel"
            disabled={pending}
            onClick={close}
          >
            Cancel
          </Button>
          <button
            type="button"
            className="editor-reset"
            disabled={pending}
            onClick={reset}
          >
            <ToolGlyph kind="reset" />
            Reset
          </button>
          <button
            type="button"
            className="editor-save"
            disabled={pending}
            onClick={() => {
              preview.mutate(draft, {
                onSuccess: (result) => {
                  apply.mutate(result.id, { onSuccess: close });
                },
              });
            }}
          >
            <ToolGlyph kind="save" />
            {pending ? "Saving…" : "Save edit"}
          </button>
        </div>
      </footer>
    </Dialog>
  );
}
