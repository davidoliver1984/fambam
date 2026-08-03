import { apiClient, ensureCsrfCookie } from "@/api/client";

export type LoginInput = {
  email: string;
  password: string;
  remember: boolean;
};

export type ResetPasswordInput = {
  token: string | null;
  email: string | null;
  password: string;
  password_confirmation: string;
};

export type LoginResult = {
  two_factor: boolean;
};

export async function login(input: LoginInput): Promise<LoginResult> {
  await ensureCsrfCookie();
  const response = await apiClient.post<LoginResult>("/login", input);

  return response.data;
}

export async function requestPasswordReset(email: string): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/forgot-password", { email });
}

export async function resetPassword(input: ResetPasswordInput): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/reset-password", input);
}

export async function logout(): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/logout");
}
