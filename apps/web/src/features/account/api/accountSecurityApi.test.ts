import { http, HttpResponse } from "msw";
import { describe, expect, it } from "vitest";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import { server } from "@/test/msw/server";

import { revokeSessions, updatePassword } from "./accountSecurityApi";

const apiBaseUrl = "http://localhost:8082";

describe("accountSecurityApi", () => {
  it("preserves Laravel field errors from a rejected password change", async () => {
    expect.assertions(1);

    try {
      await updatePassword({
        current_password: "current-password",
        password: "compromised-password",
        password_confirmation: "compromised-password",
      });
    } catch (error) {
      expect(toLaravelFieldErrors(error)).toEqual({
        password: "Use a password that has not appeared in a breach.",
      });
    }
  });

  it("revokes sessions through the account-security endpoint", async () => {
    let requests = 0;
    server.use(
      http.post(`${apiBaseUrl}/api/user/revoke-sessions`, () => {
        requests += 1;
        return new HttpResponse(null, { status: 204 });
      }),
    );

    await expect(revokeSessions()).resolves.toBeUndefined();
    expect(requests).toBe(1);
  });

  it.each([401, 403, 429])(
    "preserves a %i revoke-sessions response",
    async (status) => {
      server.use(
        http.post(`${apiBaseUrl}/api/user/revoke-sessions`, () =>
          HttpResponse.json({ message: "Request rejected." }, { status }),
        ),
      );

      try {
        await revokeSessions();
        throw new Error("Expected revocation to fail");
      } catch (error) {
        expect(toAppError(error).status).toBe(status);
      }
    },
  );
});
