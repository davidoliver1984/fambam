export type PhotoTextContent = {
  id: string;
  body: string;
  author: { id: number; name: string } | null;
  edited_at: string | null;
  created_at: string;
  permissions: { can_edit: boolean; can_remove: boolean };
};
export type PhotoReactionType = "love" | "smile" | "laugh" | "remember";
export type PhotoConversation = {
  stories: PhotoTextContent[];
  comments: PhotoTextContent[];
  reactions: Array<{
    user_id: number;
    name: string;
    reaction: PhotoReactionType;
  }>;
  permissions: { can_interact: boolean; can_author_story: boolean };
};
