import { z } from "zod";

import { passwordField } from "@/features/auth/validation/passwordSchemas";

export const invitationAcceptanceSchema = z
  .object({
    name: z.string().min(1, "Enter your display name.").max(255),
    timezone: z.string().min(1, "Enter your timezone."),
    password: passwordField,
    password_confirmation: z.string(),
  })
  .refine((values) => values.password === values.password_confirmation, {
    message: "The passwords do not match.",
    path: ["password_confirmation"],
  });

export type InvitationAcceptanceFields = z.infer<
  typeof invitationAcceptanceSchema
>;
