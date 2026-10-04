import type { RichTextDocument } from "@/features/stories/types/story";

export type AlbumVisibility = "private" | "selected" | "family_space";
export type GuestParticipation = "none" | "view" | "contribute";

export type AlbumPhoto = {
  id: string;
  media_upload_id: string;
  caption: string | null;
  client_filename: string;
  visibility: "private" | "family_space";
  position: number;
  historical_date?: {
    precision:
      "exact" | "month" | "year" | "decade" | "approximate" | "unknown";
    value: string | null;
  } | null;
  conversation?: {
    love_count: number;
    comment_count: number;
    viewer_has_loved: boolean;
    can_interact: boolean;
  };
};

export type Album = {
  id: string;
  name: string;
  description: string | null;
  description_document?: unknown;
  description_html?: string;
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
  is_new: boolean;
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
  permissions: {
    can_manage: boolean;
    can_contribute: boolean;
    can_delete?: boolean;
  };
};

export type AlbumSort = "newest" | "oldest" | "updated";

export type AlbumListCriteria = {
  sort?: AlbumSort;
  q?: string;
  location?: string;
  date_from?: string;
  date_to?: string;
  tag_id?: string;
  person_ids?: string[];
  event_id?: string;
  limit?: number;
};

export type AlbumListPage = {
  items: Album[];
  next_cursor: string | null;
};

export type CreateAlbumInput = {
  name: string;
  description: RichTextDocument | null;
  visibility: AlbumVisibility;
  event_id?: string | null;
  guest_participation?: GuestParticipation;
  starts_on?: string | null;
  ends_on?: string | null;
  location?: string | null;
  tags?: string[];
  person_ids?: string[];
  cover_photo_id?: string | null;
  confirm_visibility_widening?: boolean;
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
