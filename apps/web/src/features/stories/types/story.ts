import type { FamilyEntity } from "@/navigation/familyEntityPath";

export type RichTextMark = "bold" | "italic";

export type RichTextTextNode = {
  type: "text";
  text: string;
  marks?: RichTextMark[];
};

export type RichTextMentionNode = {
  type: "mention";
  mention_id?: string;
  person_id: string;
  label: string;
};

export type RichTextInlineNode = RichTextTextNode | RichTextMentionNode;

export type RichTextTextBlock = {
  type: "paragraph" | "heading_2" | "heading_3";
  content: RichTextInlineNode[];
};

export type RichTextBlock = RichTextTextBlock | { type: "horizontal_rule" };

export type RichTextDocument = {
  schema_version: 1;
  blocks: RichTextBlock[];
};

export type ActorPresentation = {
  id: number | null;
  display_name: string;
  person_id: string | null;
  initials: string;
  portrait_thumbnail_url: string | null;
};

export type StoryHeroPresentation = {
  source_type: "photo" | "album_cover" | "event_preview" | "person_portrait";
  photo_id: string | null;
  url: string;
  method: "GET";
  expires_at: string | null;
};

export type StoryComment = {
  id: string;
  body: RichTextDocument;
  body_html: string;
  author: ActorPresentation;
  created_at: string | null;
  permissions: { can_remove: boolean };
};

export type Story = {
  id: string;
  heading: string;
  body: RichTextDocument;
  body_html: string;
  body_plain_text: string;
  subject: FamilyEntity & { label: string };
  hero: StoryHeroPresentation | null;
  author: ActorPresentation;
  comments: StoryComment[];
  created_at: string | null;
  edited_at: string | null;
  permissions: { can_edit: boolean; can_remove: boolean };
};

export type CreateStoryInput = {
  subject_type: FamilyEntity["type"];
  subject_id: string;
  body: RichTextDocument;
};

export function plainTextDocument(text: string): RichTextDocument {
  return {
    schema_version: 1,
    blocks: [
      { type: "paragraph", content: [{ type: "text", text: text.trim() }] },
    ],
  };
}

export function emptyRichTextDocument(): RichTextDocument {
  return plainTextDocument("");
}

export function richTextPlainText(document: RichTextDocument): string {
  return document.blocks
    .filter(
      (block): block is RichTextTextBlock => block.type !== "horizontal_rule",
    )
    .map((block) =>
      block.content
        .map((node) => (node.type === "mention" ? node.label : node.text))
        .join(""),
    )
    .join("\n\n");
}
