export type FamilyCollection = {
  id: string;
  name: string;
  description: string | null;
  created_at: string | null;
  photos?: Array<{
    id: string;
    caption: string | null;
    media_upload_id: string;
    position: number;
  }>;
};

export type CollectionInput = { name: string; description: string | null };
