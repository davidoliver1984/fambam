import { describe, expect, it } from "vitest";

import { toLaravelFieldErrors } from "@/api/errors";

import { updatePassword } from "./accountSecurityApi";

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
});
