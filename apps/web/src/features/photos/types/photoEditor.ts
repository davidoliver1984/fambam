export type PhotoFilter =
  "black_and_white" | "sepia" | "warm" | "cool" | "sharpen";

export type EditRecipe = {
  schema_version: 1;
  crop: { x: number; y: number; width: number; height: number } | null;
  rotate_degrees: 0 | 90 | 180 | 270;
  flip_horizontal: boolean;
  flip_vertical: boolean;
  straighten_degrees: number;
  adjustments: {
    brightness: number;
    contrast: number;
    saturation: number;
    warmth: number;
  };
  filter: { name: PhotoFilter | null; intensity: number };
};

export type RestoreProvenance = {
  schema_version: 1;
  algorithm_version: "restore-v1";
  processing_mode: "conservative";
  parameters: {
    white_balance_shift: number;
    exposure_adjustment: number;
    saturation_recovery: number;
    denoise_strength: number;
    sharpen_strength: number;
  };
  outcome: "applied";
};

export type PhotoVersion = {
  id: string;
  edit_recipe: EditRecipe;
  restore: RestoreProvenance | null;
  created_at: string;
};

export type PhotoVersions = {
  active_photo_version_id: string | null;
  can_edit: boolean;
  versions: PhotoVersion[];
};

export type PhotoEditPreview = {
  outcome: "preview_ready";
  id: string;
  edit_recipe: EditRecipe;
  restore: RestoreProvenance | null;
  expires_at: string;
};

export type RestorePreviewResult =
  PhotoEditPreview | { outcome: "no_improvement_found" };

export type VersionDelivery = {
  asset: "photo_version" | "photo_edit_preview";
  url: string;
  method: "GET";
  expires_at: string;
};

export const identityEditRecipe: EditRecipe = {
  schema_version: 1,
  crop: null,
  rotate_degrees: 0,
  flip_horizontal: false,
  flip_vertical: false,
  straighten_degrees: 0,
  adjustments: { brightness: 0, contrast: 0, saturation: 0, warmth: 0 },
  filter: { name: null, intensity: 0 },
};
