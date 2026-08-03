import { apiClient } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";
import type { UpdateProfileInput, User } from "../types/user";

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
