import { z } from "zod";

const datePrecision = z.enum([
  "exact",
  "month",
  "year",
  "decade",
  "approximate",
  "unknown",
]);

export const personFormSchema = z
  .object({
    preferred_name: z.string().trim().min(1).max(120),
    alternate_names: z.string().max(1000),
    birth_precision: datePrecision,
    birth_value: z.string().max(10),
    is_deceased: z.boolean(),
    death_precision: datePrecision,
    death_value: z.string().max(10),
    biography: z.string().max(5000),
  })
  .superRefine((value, context) => {
    for (const [precision, dateValue, path] of [
      [value.birth_precision, value.birth_value, "birth_value"],
      [value.death_precision, value.death_value, "death_value"],
    ] as const) {
      if (precision === "unknown" && dateValue !== "") {
        context.addIssue({
          code: "custom",
          path: [path],
          message: "Leave the value empty when the date is unknown.",
        });
      }
      if (precision !== "unknown" && dateValue === "") {
        context.addIssue({
          code: "custom",
          path: [path],
          message: "Enter a value for this date precision.",
        });
      }
    }
    if (!value.is_deceased && value.death_precision !== "unknown") {
      context.addIssue({
        code: "custom",
        path: ["death_precision"],
        message: "Death information requires the Person to be deceased.",
      });
    }
  });

export type PersonFormFields = z.infer<typeof personFormSchema>;
