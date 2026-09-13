export type FamilyExportState =
  "pending" | "processing" | "ready" | "failed" | "expired";

export type FamilyExport = {
  id: string;
  scope: "family_space_full" | "personal";
  state: FamilyExportState;
  photo_count: number | null;
  byte_size: number | null;
  failure_reason: string | null;
  expires_at: string | null;
  created_at: string | null;
};

export type FamilyExportDownload = {
  url: string;
  expires_at: string;
};
