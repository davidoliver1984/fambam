import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import { server } from "@/test/msw/server";

import { login, logout, requestPasswordReset, resetPassword } from "./authApi";

const apiBaseUrl = "http://localhost:8082";

describe("authApi", () => {
  it("runs login and logout through their typed transport paths", async () => {
    const requests: string[] = [];
    server.use(
      http.post(`${apiBaseUrl}/login`, async ({ request }) => {
        const input = (await request.json()) as Record<string, unknown>;
        requests.push(`login:${String(input.email)}:${String(input.remember)}`);
        return HttpResponse.json({ two_factor: true });
      }),
      http.post(`${apiBaseUrl}/logout`, () => {
        requests.push("logout");
        return new HttpResponse(null, { status: 204 });
      }),
    );

    await expect(
      login({
        email: "relative@example.test",
        password: "private-passphrase",
        remember: true,
      }),
    ).resolves.toEqual({ two_factor: true });
    await expect(logout()).resolves.toBeUndefined();
    expect(requests).toEqual(["login:relative@example.test:true", "logout"]);
  });

  it("preserves generic login validation without exposing account state", async () => {
    server.use(
      http.post(`${apiBaseUrl}/login`, () =>
        HttpResponse.json(
          {
            message: "The given data was invalid.",
            errors: { email: ["These credentials do not match our records."] },
          },
          { status: 422 },
        ),
      ),
    );

    try {
      await login({
        email: "unknown@example.test",
        password: "private-passphrase",
        remember: false,
      });
      throw new Error("Expected login to fail");
    } catch (error) {
      expect(toLaravelFieldErrors(error)).toEqual({
        email: "These credentials do not match our records.",
      });
    }
  });

  it("surfaces password-reset throttling while retaining reset transport", async () => {
    let resetRequests = 0;
    server.use(
      http.post(
        `${apiBaseUrl}/forgot-password`,
        () => new HttpResponse(null, { status: 204 }),
      ),
      http.post(`${apiBaseUrl}/reset-password`, () => {
        resetRequests += 1;
        return HttpResponse.json(
          { message: "Too Many Attempts." },
          { status: 429 },
        );
      }),
    );

    await expect(
      requestPasswordReset("relative@example.test"),
    ).resolves.toBeUndefined();

    try {
      await resetPassword({
        token: "reset-token",
        email: "relative@example.test",
        password: "replacement-passphrase",
        password_confirmation: "replacement-passphrase",
      });
      throw new Error("Expected reset to be throttled");
    } catch (error) {
      expect(toAppError(error).status).toBe(429);
    }
    expect(resetRequests).toBe(1);
  });
});
