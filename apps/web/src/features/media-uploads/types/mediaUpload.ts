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
  upload_batch_id: string | null;
  upload_authorization: UploadAuthorization | null;
};

export type MediaUploadBatchStatus = {
  batch_id: string;
  total: number;
  active: boolean;
  counts: Record<MediaUploadState, number>;
  items: Array<{
    id: string;
    state: MediaUploadState;
    client_filename: string;
    byte_size: number | null;
    uploaded_at: string | null;
    rejection_reason?: string | null;
  }>;
};

export type MediaUploadBatchInput = {
  batchId: string;
  items: Array<{ file: File; idempotencyKey: string }>;
};

export type MediaUploadBatchResult = {
  batch_id: string;
  outcomes: Array<
    | {
        status: "uploaded";
        item_key: string;
        client_filename: string;
        upload: MediaUpload;
      }
    | {
        status: "failed";
        item_key: string;
        client_filename: string;
        message: string;
      }
  >;
};
