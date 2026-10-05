import { z } from "zod";

const archiveHealthMetricSchema = z
  .object({
    numerator: z.number().int().nonnegative(),
    denominator: z.number().int().nonnegative(),
    percentage: z.number().min(0).max(100).nullable(),
  })
  .strict();

export const settingsOverviewSchema = z
  .object({
    counts: z
      .object({
        people: z.number().int().nonnegative(),
        photos: z.number().int().nonnegative(),
        albums: z.number().int().nonnegative(),
        stories: z.number().int().nonnegative(),
      })
      .strict(),
    archive_health: z
      .object({
        photos_dated: archiveHealthMetricSchema,
        faces_identified: archiveHealthMetricSchema,
        people_connected: archiveHealthMetricSchema,
      })
      .strict(),
  })
  .strict();

export type ArchiveHealthMetric = z.infer<typeof archiveHealthMetricSchema>;
export type SettingsOverview = z.infer<typeof settingsOverviewSchema>;
