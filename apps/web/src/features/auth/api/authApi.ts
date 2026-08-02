import { apiClient, ensureCsrfCookie } from "../../../api/client";

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

export async function login(input: LoginInput): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/login", input);
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
