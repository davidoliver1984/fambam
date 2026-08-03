import { z } from "zod";

export const twoFactorChallengeSchema = z
  .object({ code: z.string(), recovery_code: z.string() })
  .refine((values) => values.code !== "" || values.recovery_code !== "", {
    message: "Enter an authenticator code or recovery code.",
    path: ["code"],
  });

export type TwoFactorChallengeFields = z.infer<typeof twoFactorChallengeSchema>;
