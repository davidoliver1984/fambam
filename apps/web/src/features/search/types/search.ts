export type SearchGroup = "people" | "photos" | "albums" | "events" | "stories";

export type SearchCriteria = {
  q?: string;
  date_from?: string;
  date_to?: string;
  tag_id?: string;
  person_ids?: string[];
  event_id?: string;
  album_id?: string;
  uploaded_by?: number;
  visibility?: "family_space" | "selected" | "private";
};

export type SearchPersonSummary = {
  id: string;
  preferred_name: string;
};

export type PersonSearchSummary = SearchPersonSummary;

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

export type EventSearchSummary = {
  id: string;
  name: string;
  description: string | null;
  location: string | null;
  starts_on: string | null;
  ends_on: string | null;
};

export type SearchItems = {
  people: PersonSearchSummary;
  photos: PhotoSearchSummary;
  albums: AlbumSearchSummary;
  events: EventSearchSummary;
  stories: StorySearchSummary;
};

export type SearchPage<Group extends SearchGroup> = {
  items: SearchItems[Group][];
  next_cursor: string | null;
};

export type SearchSuggestionType =
  "people" | "albums" | "events" | "tags" | "uploaders";
export type SearchSuggestion = { id: string; label: string };

export type DiscoveryResponse = {
  source: {
    type: "people" | "photos" | "albums" | "events";
    id: string;
  };
  related: Partial<{
    people: PersonSearchSummary[];
    photos: PhotoSearchSummary[];
    albums: AlbumSearchSummary[];
    events: EventSearchSummary[];
    stories: StorySearchSummary[];
  }>;
};

export type SavedSearchFilters = SearchCriteria & { schema_version: 1 };

export type SavedSearch = {
  id: string;
  name: string;
  filters: SavedSearchFilters;
  people: PersonSearchSummary[];
};

export type SavedSearchInput = {
  name: string;
  filters: SearchCriteria;
};
