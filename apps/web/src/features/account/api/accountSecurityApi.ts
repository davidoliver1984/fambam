import { apiClient, ensureCsrfCookie } from "@/api/client";

export type UpdatePasswordInput = {
  current_password: string;
  password: string;
  password_confirmation: string;
};

export async function updatePassword(
  input: UpdatePasswordInput,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.put("/api/user/password", input);
}

export async function revokeSessions(): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/api/user/revoke-sessions");
}
