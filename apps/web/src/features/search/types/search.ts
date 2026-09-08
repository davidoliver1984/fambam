export type SearchGroup = "photos" | "albums" | "stories";

export type SearchCriteria = {
  q?: string;
  date_from?: string;
  date_to?: string;
  tag_id?: string;
};

export type SearchPersonSummary = {
  id: string;
  preferred_name: string;
};

export type PhotoSearchSummary = {
  id: string;
  media_upload_id: string;
  caption: string | null;
  description: string | null;
  location_description: string | null;
  historical_date: { precision: string; value: string | null } | null;
  people: SearchPersonSummary[];
};

export type AlbumSearchSummary = {
  id: string;
  name: string;
  description: string | null;
  visibility: string;
  event_id: string | null;
};

export type StorySearchSummary = {
  id: string;
  photo_id: string;
  photo_caption: string | null;
  media_upload_id: string;
  excerpt: string;
  created_at: string;
};

export type SearchItems = {
  photos: PhotoSearchSummary;
  albums: AlbumSearchSummary;
  stories: StorySearchSummary;
};

export type SearchPage<Group extends SearchGroup> = {
  items: SearchItems[Group][];
  next_cursor: string | null;
};
