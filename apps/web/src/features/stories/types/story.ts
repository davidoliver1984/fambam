import type { FamilyEntity } from "@/navigation/familyEntityPath";

export type RichTextDocument = {
  schema_version: 1;
  blocks: Array<{
    type: "paragraph";
    content: Array<{ type: "text"; text: string }>;
  }>;
};

export type StoryComment = {
  id: string;
  body: RichTextDocument;
  body_html: string;
  author: { id: number; name: string } | null;
  created_at: string | null;
  permissions: { can_remove: boolean };
};

export type Story = {
  id: string;
  heading: string;
  body: RichTextDocument;
  body_html: string;
  body_plain_text: string;
  subject: FamilyEntity;
  author: { id: number; name: string } | null;
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
