import type { FamilyActivity } from "@/features/activities/types/familyActivity";
import type { DateMemory } from "@/features/memories/types/dateMemory";

export type HomePresentationPhoto = {
  id: string;
  media_upload_id: string;
  active_photo_version_id: string | null;
  alt: string | null;
  presentation: {
    url: string;
    method: "GET";
    expires_at: string;
  };
};

export type HomeEngagement = {
  love_count: number;
  comment_count: number;
};

export type HomeAlbumActivity = FamilyActivity & {
  action_type: "photos_added_to_album";
  album: {
    id: string;
    name: string;
    description: string | null;
    starts_on: string | null;
    ends_on: string | null;
    location: string | null;
  };
  feature_photo: HomePresentationPhoto | null;
  engagement: HomeEngagement;
};

export type HomeStoryActivity = FamilyActivity & {
  action_type: "story_added";
  story: {
    id: string;
    excerpt: string;
    subject: {
      type: "person" | "photo" | "album" | "event";
      id: string;
    };
  };
  engagement: HomeEngagement;
};

export type HomeReadModel = {
  activity: Array<HomeAlbumActivity | HomeStoryActivity>;
  latest_photos: HomePresentationPhoto[];
  on_this_day: (DateMemory & { photo: HomePresentationPhoto }) | null;
};
