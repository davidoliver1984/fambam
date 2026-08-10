export type MediaUploadState =
  | "initiated"
  | "uploaded"
  | "verifying"
  | "preserved"
  | "processing"
  | "ready"
  | "quarantined"
  | "abandoned"
  | "degraded";

export type UploadAuthorization = {
  url: string;
  method: "PUT";
  headers: Record<string, string>;
  expires_at: string;
};

export type MediaUpload = {
  id: string;
  state: MediaUploadState;
  client_filename: string;
  byte_size: number | null;
  uploaded_at: string | null;
  upload_authorization: UploadAuthorization | null;
};
