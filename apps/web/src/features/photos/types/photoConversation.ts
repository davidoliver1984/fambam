export type PhotoCommentAuthor = {
  id: number;
  name: string;
  person_id: string | null;
  initials: string;
  portrait_thumbnail_url: string | null;
};

export type PhotoTextContent = {
  id: string;
  parent_comment_id: string | null;
  is_deleted: boolean;
  body: string;
  body_document?: unknown;
  body_html?: string;
  author: PhotoCommentAuthor | null;
  edited_at: string | null;
  created_at: string;
  permissions: { can_edit: boolean; can_remove: boolean };
};

export type PhotoReply = PhotoTextContent & {
  parent_comment_id: string;
  is_deleted: false;
};

export type PhotoComment = PhotoTextContent & {
  parent_comment_id: null;
  replies: PhotoReply[];
};
export type PhotoReactionType = "love" | "smile" | "laugh" | "remember";
export type PhotoConversation = {
  stories: PhotoTextContent[];
  comments: PhotoComment[];
  reactions: Array<{
    user_id: number;
    name: string;
    reaction: PhotoReactionType;
  }>;
  permissions: { can_interact: boolean; can_author_story: boolean };
  conversation_scope: "legacy" | "album";
  album_id: string | null;
};
