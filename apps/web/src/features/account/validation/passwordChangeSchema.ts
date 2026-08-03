import { z } from "zod";

import { passwordField } from "@/features/auth/validation/passwordSchemas";

export const passwordChangeSchema = z
  .object({
    current_password: z.string().min(1, "Enter your current password."),
    password: passwordField,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords do not match.",
    path: ["password_confirmation"],
  });

export type PasswordChangeFields = z.infer<typeof passwordChangeSchema>;
