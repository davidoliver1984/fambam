import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import {
  completeMediaUpload,
  putStagedObject,
} from "@/features/media-uploads/api/mediaUploadApi";
import type { MediaUpload } from "@/features/media-uploads/types/mediaUpload";
import type { RecentSignIn, UpdateProfileInput, User } from "../types/user";

export async function getCurrentUser(signal?: AbortSignal): Promise<User> {
  return unwrap(
    await apiClient.get<ApiEnvelope<User>>("/api/user", { signal }),
  );
}

export async function updateProfile(input: UpdateProfileInput): Promise<User> {
  return unwrap(
    await apiClient.patch<ApiEnvelope<User>>("/api/user/profile", input),
  );
}

export async function requestEmailChange(input: {
  email: string;
  current_password: string;
}): Promise<{
  email: string;
  pending_email: string;
  pending_email_requested_at: string;
}> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<
      ApiEnvelope<{
        email: string;
        pending_email: string;
        pending_email_requested_at: string;
      }>
    >("/api/user/email-change", input),
  );
}

export async function getRecentSignIns(
  signal?: AbortSignal,
): Promise<RecentSignIn[]> {
  return unwrap(
    await apiClient.get<ApiEnvelope<RecentSignIn[]>>(
      "/api/user/recent-sign-ins",
      { signal },
    ),
  );
}

export async function uploadAccountAvatar(
  familySlug: string,
  file: File,
  idempotencyKey: string,
): Promise<string> {
  await ensureCsrfCookie();
  const upload = unwrap(
    await apiClient.post<ApiEnvelope<MediaUpload>>(
      `/api/families/${encodeURIComponent(familySlug)}/account/avatar-uploads`,
      { client_filename: file.name, client_mime_type: file.type || null },
      { headers: { "Idempotency-Key": idempotencyKey } },
    ),
  );
  if (upload.state === "initiated") {
    if (upload.upload_authorization === null)
      throw new Error("Upload authority was not returned for this avatar.");
    await putStagedObject(upload.upload_authorization, file);
    await completeMediaUpload(familySlug, upload.id);
  }
  return upload.id;
}

export async function setAccountAvatar(mediaUploadId: string): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.put("/api/user/avatar", { media_upload_id: mediaUploadId });
}

export async function removeAccountAvatar(): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete("/api/user/avatar");
}
