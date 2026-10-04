import type { UncertainDate } from "@/features/photos/types/photo";

export type CollectionPurpose = "prints" | "calendar";

export type CollectionListCriteria = {
  q?: string;
  sort?: "updated" | "name";
  collection_id?: string;
  purpose?: CollectionPurpose | CollectionPurpose[];
};

export type FamilyCollection = {
  id: string;
  name: string;
  description: string | null;
  purpose: CollectionPurpose | null;
  created_at: string | null;
  updated_at: string | null;
  photo_count: number;
  preview_photo: {
    photo_id: string;
    media_upload_id: string;
  } | null;
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

export type CollectionInput = {
  name: string;
  description: string | null;
  purpose?: CollectionPurpose | null;
};
export type CollectionUpdateInput = Partial<CollectionInput>;
