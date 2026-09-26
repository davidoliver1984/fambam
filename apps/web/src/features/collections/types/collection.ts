import type { UncertainDate } from "@/features/photos/types/photo";

export type FamilyCollection = {
  id: string;
  name: string;
  description: string | null;
  created_at: string | null;
  photos?: Array<{
    id: string;
    caption: string | null;
    media_upload_id: string;
    historical_date: UncertainDate | null;
    location_description: string | null;
    people: Array<{ id: string; preferred_name: string }>;
    position: number;
  }>;
};

export type CollectionInput = { name: string; description: string | null };
export type CollectionUpdateInput = Partial<CollectionInput>;
