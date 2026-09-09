export type FamilyActivityType =
  | "photos_added_to_album"
  | "story_added"
  | "person_identity_confirmed"
  | "album_created"
  | "event_created";

export type FamilyActivity = {
  id: string;
  action_type: FamilyActivityType;
  actor: {
    user_id: number;
    name: string;
    person_id: string | null;
  };
  subject: {
    type: "album" | "event" | "story" | "person";
    id: string;
    label: string;
    photo_id?: string;
  };
  contribution_batch_id: string | null;
  photo_ids: string[];
  photo_count: number;
  created_at: string;
};
