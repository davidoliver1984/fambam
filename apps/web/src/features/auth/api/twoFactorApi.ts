import { apiClient, ensureCsrfCookie } from "@/api/client";

export type TwoFactorQrCode = {
  svg: string;
  url: string;
};

export type TwoFactorChallengeInput =
  | { code: string; recovery_code?: never }
  | { code?: never; recovery_code: string };

export type RecoveryCodesResponse = {
  recovery_codes: string[];
};

export async function confirmPassword(password: string): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/user/confirm-password", { password });
}

export async function enableTwoFactor(): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/user/two-factor-authentication");
}

export async function getTwoFactorQrCode(
  signal?: AbortSignal,
): Promise<TwoFactorQrCode> {
  const response = await apiClient.get<TwoFactorQrCode>(
    "/user/two-factor-qr-code",
    { signal },
  );

  return response.data;
}

export async function regenerateRecoveryCodes(): Promise<string[]> {
  await ensureCsrfCookie();
  const response = await apiClient.post<RecoveryCodesResponse>(
    "/user/two-factor-recovery-codes",
  );

  return response.data.recovery_codes;
}

export async function confirmTwoFactor(code: string): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/user/confirmed-two-factor-authentication", { code });
}

export async function disableTwoFactor(): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete("/user/two-factor-authentication");
}

export async function completeTwoFactorChallenge(
  input: TwoFactorChallengeInput,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.post("/two-factor-challenge", input);
}
