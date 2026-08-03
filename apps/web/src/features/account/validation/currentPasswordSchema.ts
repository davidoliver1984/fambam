import { z } from "zod";

export const currentPasswordSchema = z.object({
  current_password: z.string().min(1, "Enter your current password."),
});

export type CurrentPasswordFields = z.infer<typeof currentPasswordSchema>;
