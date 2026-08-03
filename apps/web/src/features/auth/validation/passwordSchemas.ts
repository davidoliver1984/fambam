import { z } from "zod";

export const passwordField = z
  .string()
  .min(15, "Use at least 15 characters.")
  .max(255, "Use no more than 255 characters.");

export const confirmedPasswordSchema = z
  .object({
    password: passwordField,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords do not match.",
    path: ["password_confirmation"],
  });

export type ConfirmedPasswordFields = z.infer<typeof confirmedPasswordSchema>;
