export type AlbumVisibility = "private" | "selected" | "family_space";

export type AlbumPhoto = {
  id: string;
  caption: string | null;
  client_filename: string;
  visibility: "private" | "family_space";
  position: number;
};

export type Album = {
  id: string;
  name: string;
  description: string | null;
  visibility: AlbumVisibility;
  created_by: number | null;
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
};
