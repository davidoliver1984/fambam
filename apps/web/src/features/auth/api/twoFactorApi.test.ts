import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import { server } from "@/test/msw/server";

import {
  completeTwoFactorChallenge,
  confirmPassword,
  confirmTwoFactor,
  disableTwoFactor,
  enableTwoFactor,
  getTwoFactorQrCode,
  regenerateRecoveryCodes,
} from "./twoFactorApi";

const apiBaseUrl = "http://localhost:8082";

describe("twoFactorApi", () => {
  it("supports setup, one-time recovery generation, challenge and disable", async () => {
    const requests: string[] = [];
    server.use(
      http.post(`${apiBaseUrl}/user/confirm-password`, () => {
        requests.push("confirm-password");
        return new HttpResponse(null, { status: 201 });
      }),
      http.post(`${apiBaseUrl}/user/two-factor-authentication`, () => {
        requests.push("enable");
        return new HttpResponse(null, { status: 200 });
      }),
      http.post(`${apiBaseUrl}/user/two-factor-recovery-codes`, () => {
        requests.push("generate-recovery");
        return HttpResponse.json({
          recovery_codes: ["recovery-one", "recovery-two"],
        });
      }),
      http.get(`${apiBaseUrl}/user/two-factor-qr-code`, () => {
        requests.push("qr");
        return HttpResponse.json({
          svg: "<svg />",
          url: "otpauth://totp/fambam",
        });
      }),
      http.post(
        `${apiBaseUrl}/user/confirmed-two-factor-authentication`,
        () => {
          requests.push("confirm-two-factor");
          return new HttpResponse(null, { status: 200 });
        },
      ),
      http.post(`${apiBaseUrl}/two-factor-challenge`, async ({ request }) => {
        const input = (await request.json()) as Record<string, unknown>;
        requests.push(`challenge:${String(input.recovery_code)}`);
        return new HttpResponse(null, { status: 204 });
      }),
      http.delete(`${apiBaseUrl}/user/two-factor-authentication`, () => {
        requests.push("disable");
        return new HttpResponse(null, { status: 200 });
      }),
    );

    await confirmPassword("current-password");
    await enableTwoFactor();
    await expect(regenerateRecoveryCodes()).resolves.toEqual([
      "recovery-one",
      "recovery-two",
    ]);
    await expect(getTwoFactorQrCode()).resolves.toEqual({
      svg: "<svg />",
      url: "otpauth://totp/fambam",
    });
    await confirmTwoFactor("123456");
    await completeTwoFactorChallenge({ recovery_code: "recovery-one" });
    await disableTwoFactor();

    expect(requests).toEqual([
      "confirm-password",
      "enable",
      "generate-recovery",
      "qr",
      "confirm-two-factor",
      "challenge:recovery-one",
      "disable",
    ]);
  });

  it("preserves 403, 422 and 429 security responses", async () => {
    server.use(
      http.post(`${apiBaseUrl}/user/two-factor-authentication`, () =>
        HttpResponse.json(
          { message: "Password confirmation required." },
          { status: 403 },
        ),
      ),
      http.post(`${apiBaseUrl}/user/confirmed-two-factor-authentication`, () =>
        HttpResponse.json(
          { message: "Invalid.", errors: { code: ["The code was invalid."] } },
          { status: 422 },
        ),
      ),
      http.post(`${apiBaseUrl}/two-factor-challenge`, () =>
        HttpResponse.json({ message: "Too Many Attempts." }, { status: 429 }),
      ),
    );

    await expect(statusOf(enableTwoFactor())).resolves.toBe(403);
    try {
      await confirmTwoFactor("000000");
      throw new Error("Expected confirmation to fail");
    } catch (error) {
      expect(toLaravelFieldErrors(error)).toEqual({
        code: "The code was invalid.",
      });
    }
    await expect(
      statusOf(completeTwoFactorChallenge({ code: "000000" })),
    ).resolves.toBe(429);
  });
});

async function statusOf(request: Promise<unknown>): Promise<number | null> {
  try {
    await request;
    return null;
  } catch (error) {
    return toAppError(error).status;
  }
}
