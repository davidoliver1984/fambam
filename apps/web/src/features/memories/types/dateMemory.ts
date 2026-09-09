import type { UncertainDate } from "@/features/photos/types/photo";

export type DateMemory = {
  photo_id: string;
  media_upload_id: string;
  label: string;
  reason: string;
  historical_date: UncertainDate;
  added_at: string | null;
};
