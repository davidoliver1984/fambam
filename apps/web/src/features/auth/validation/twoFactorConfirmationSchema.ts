import { z } from "zod";

export const twoFactorConfirmationSchema = z.object({
  code: z.string().regex(/^\d{6}$/, "Enter the six-digit code."),
});

export type TwoFactorConfirmationFields = z.infer<
  typeof twoFactorConfirmationSchema
>;
