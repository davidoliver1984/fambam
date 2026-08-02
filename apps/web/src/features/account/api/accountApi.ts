import type { AxiosResponse } from "axios";

import { apiClient } from "../../../api/client";
import type { UpdateProfileInput, User } from "../types/user";

type ApiEnvelope<T> = {
  data: T;
};

function unwrap<T>(response: AxiosResponse<ApiEnvelope<T>>): T {
  return response.data.data;
}

export async function getCurrentUser(): Promise<User> {
  return unwrap(await apiClient.get<ApiEnvelope<User>>("/api/user"));
}

export async function updateProfile(input: UpdateProfileInput): Promise<User> {
  return unwrap(
    await apiClient.patch<ApiEnvelope<User>>("/api/user/profile", input),
  );
}
