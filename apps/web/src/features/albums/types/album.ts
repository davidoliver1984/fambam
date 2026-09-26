export type AlbumVisibility = "private" | "selected" | "family_space";
export type GuestParticipation = "none" | "view" | "contribute";

export type AlbumPhoto = {
  id: string;
  media_upload_id: string;
  caption: string | null;
  client_filename: string;
  visibility: "private" | "family_space";
  position: number;
};

export type Album = {
  id: string;
  name: string;
  description: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  location?: string | null;
  tags?: Array<{ id: string; label: string }>;
  people?: Array<{ id: string; name: string }>;
  cover?: {
    photo_id: string;
    media_upload_id: string;
    focal_x: number;
    focal_y: number;
  } | null;
  cover_pending?: boolean;
  visibility: AlbumVisibility;
  created_by: number | null;
  creator: { id: number; name: string } | null;
  created_at: string;
  updated_at: string;
  photo_count: number;
  event_id?: string | null;
  event?: { id: string; name: string; starts_on: string | null } | null;
  guest_participation: GuestParticipation;
  photos: AlbumPhoto[];
  grants: Array<{
    membership_id: string;
    name: string;
    can_view: boolean;
    can_contribute: boolean;
  }>;
  permissions: { can_manage: boolean; can_contribute: boolean };
};

export type CreateAlbumInput = {
  name: string;
  description: string | null;
  visibility: AlbumVisibility;
  event_id?: string | null;
  guest_participation?: GuestParticipation;
};

export type UpdateAlbumInput = Partial<
  Pick<
    Album,
    | "name"
    | "visibility"
    | "event_id"
    | "guest_participation"
    | "starts_on"
    | "ends_on"
    | "location"
  >
> & {
  description?: string | null;
  tags?: string[];
  person_ids?: string[];
};

export type SetAlbumCoverInput = {
  photoId: string | null;
  confirmVisibilityWidening?: boolean;
  focalX?: number;
  focalY?: number;
};
