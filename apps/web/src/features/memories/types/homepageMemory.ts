export type PersonMemory = {
  person_id: string;
  preferred_name: string;
  memory_count: number;
  latest_at: string;
};

export type MemoryContext = {
  id: string;
  name: string;
};

export type RecentStoryMemory = {
  id: string;
  photo_id: string;
  photo_caption: string | null;
  media_upload_id: string;
  excerpt: string;
  created_at: string;
  author: { id: number | null; name: string };
  people: MemoryContext[];
  albums: MemoryContext[];
  events: MemoryContext[];
};

export type HomepageMemories = {
  recent_days: number;
  people: PersonMemory[];
  stories: RecentStoryMemory[];
};
