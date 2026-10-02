import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import {
  cleanup,
  render,
  screen,
  waitFor,
  within,
} from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { MemoryRouter } from "react-router";

import {
  createPhotoText,
  getPhotoConversation,
  removePhotoReaction,
  removePhotoText,
  savePhotoReaction,
  updatePhotoText,
} from "../api/photoConversationApi";
import {
  PhotoConversationPanel,
  PhotoLoveControl,
} from "./PhotoConversationPanel";

vi.mock("@/features/account/hooks/useCurrentUserQuery", () => ({
  useCurrentUserQuery: () => ({ data: { id: 1, name: "David Mercer" } }),
}));
vi.mock("../api/photoConversationApi", () => ({
  createPhotoText: vi.fn(),
  getPhotoConversation: vi.fn(),
  removePhotoReaction: vi.fn(),
  removePhotoText: vi.fn(),
  savePhotoReaction: vi.fn(),
  updatePhotoText: vi.fn(),
}));

const familySlug = "family-archive";
const photoId = "photo-1";
const albumId = "album-1";

function renderPanel(includeLove = false) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <MemoryRouter>
      <QueryClientProvider client={client}>
        {includeLove && (
          <PhotoLoveControl
            familySlug={familySlug}
            photoId={photoId}
            albumId={albumId}
          />
        )}
        <PhotoConversationPanel
          familySlug={familySlug}
          photoId={photoId}
          albumId={albumId}
        />
      </QueryClientProvider>
    </MemoryRouter>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotoConversation).mockResolvedValue({
    stories: [
      {
        id: "story-1",
        parent_comment_id: null,
        is_deleted: false,
        body: "A family day out",
        author: {
          id: 1,
          name: "David",
          person_id: null,
          initials: "D",
          portrait_thumbnail_url: null,
        },
        edited_at: null,
        created_at: "2026-08-24T10:00:00Z",
        permissions: { can_edit: true, can_remove: true },
      },
    ],
    comments: [
      {
        id: "comment-1",
        parent_comment_id: null,
        is_deleted: false,
        body: "Mum looks so happy here.",
        body_html:
          '<p>Mum looks so happy <a href="/families/family-archive/people/person-1">here</a>.</p>',
        author: {
          id: 2,
          name: "Sarah Mercer",
          person_id: "person-sarah",
          initials: "SM",
          portrait_thumbnail_url: null,
        },
        edited_at: null,
        created_at: "2026-09-25T10:00:00Z",
        permissions: { can_edit: true, can_remove: true },
        replies: [
          {
            id: "reply-1",
            parent_comment_id: "comment-1",
            is_deleted: false,
            body: "She really was — even in that wind!",
            author: {
              id: 3,
              name: "Jane Mercer",
              person_id: "person-jane",
              initials: "JM",
              portrait_thumbnail_url: null,
            },
            edited_at: null,
            created_at: "2026-09-25T11:00:00Z",
            permissions: { can_edit: true, can_remove: true },
          },
        ],
      },
    ],
    reactions: [
      { user_id: 1, name: "David Mercer", reaction: "love" },
      { user_id: 2, name: "Anne", reaction: "love" },
    ],
    permissions: { can_interact: true, can_author_story: true },
    conversation_scope: "album",
    album_id: albumId,
  });
  vi.mocked(createPhotoText).mockResolvedValue({
    id: "comment-2",
    parent_comment_id: null,
    is_deleted: false,
    body: "A wonderful memory.",
    author: {
      id: 1,
      name: "David Mercer",
      person_id: "person-david",
      initials: "DM",
      portrait_thumbnail_url: null,
    },
    edited_at: null,
    created_at: "2026-09-26T10:00:00Z",
    permissions: { can_edit: true, can_remove: true },
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoConversationPanel", () => {
  it("renders Album-context comments, inline replies and linked author avatars", async () => {
    renderPanel();
    expect(await screen.findByText("Sarah Mercer")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "here" })).toHaveAttribute(
      "href",
      "/families/family-archive/people/person-1",
    );
    expect(screen.queryByText("A family day out")).not.toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Conversation 2" }),
    ).toBeInTheDocument();
    expect(
      screen.getByText("She really was — even in that wind!"),
    ).toBeInTheDocument();
    expect(
      screen.getByRole("link", { name: "View Sarah Mercer" }),
    ).toHaveAttribute("href", "/families/family-archive/people/person-sarah");
  });

  it("creates, edits and removes comments through the typed mutation boundary", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.type(
      await screen.findByLabelText("Add a comment"),
      "A wonderful memory.{Enter}",
    );
    expect(createPhotoText).toHaveBeenCalledWith(
      familySlug,
      photoId,
      "comments",
      "A wonderful memory.",
      albumId,
      undefined,
    );

    await user.click(screen.getAllByRole("button", { name: "Edit" })[0]);
    const editor = screen.getByDisplayValue("Mum looks so happy here.");
    await user.clear(editor);
    await user.type(editor, "Corrected memory");
    await user.click(screen.getByRole("button", { name: "Save" }));
    expect(updatePhotoText).toHaveBeenCalledWith(
      familySlug,
      photoId,
      "comments",
      "comment-1",
      "Corrected memory",
    );
    expect(removePhotoText).not.toHaveBeenCalled();
  });

  it("posts a reply against its parent comment", async () => {
    const user = userEvent.setup();
    renderPanel();
    await user.click(await screen.findByRole("button", { name: "Reply" }));
    const replyEditor = screen.getByLabelText("Reply to Sarah Mercer");
    await user.type(replyEditor, "That explains it.");
    const replyForm = replyEditor.closest("form");
    if (replyForm === null) throw new Error("Reply editor is not in a form.");
    await user.click(within(replyForm).getByRole("button", { name: "Reply" }));
    expect(createPhotoText).toHaveBeenCalledWith(
      familySlug,
      photoId,
      "comments",
      "That explains it.",
      albumId,
      "comment-1",
    );
  });

  it("keeps replies beneath a deleted parent without exposing the deleted content", async () => {
    vi.mocked(getPhotoConversation).mockResolvedValueOnce({
      stories: [],
      comments: [
        {
          id: "comment-1",
          parent_comment_id: null,
          is_deleted: true,
          body: "Comment deleted",
          author: null,
          edited_at: null,
          created_at: "2026-09-25T10:00:00Z",
          permissions: { can_edit: false, can_remove: false },
          replies: [
            {
              id: "reply-1",
              parent_comment_id: "comment-1",
              is_deleted: false,
              body: "The surviving reply.",
              author: {
                id: 3,
                name: "Jane Mercer",
                person_id: null,
                initials: "JM",
                portrait_thumbnail_url: null,
              },
              edited_at: null,
              created_at: "2026-09-25T11:00:00Z",
              permissions: { can_edit: true, can_remove: true },
            },
          ],
        },
      ],
      reactions: [],
      permissions: { can_interact: true, can_author_story: true },
      conversation_scope: "album",
      album_id: albumId,
    });

    renderPanel();

    expect(await screen.findByText("Comment deleted")).toBeInTheDocument();
    expect(screen.getByText("The surviving reply.")).toBeInTheDocument();
    expect(
      screen.queryByText("Mum looks so happy here."),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByRole("button", { name: "Reply" }),
    ).not.toBeInTheDocument();
    expect(
      screen.getByRole("heading", { name: "Conversation 1" }),
    ).toBeInTheDocument();
  });

  it("edits and removes replies without offering a second nesting level", async () => {
    const user = userEvent.setup();
    renderPanel();
    const reply = (
      await screen.findByText("She really was — even in that wind!")
    ).closest("article");
    if (reply === null) throw new Error("Reply article was not rendered.");

    expect(
      within(reply).queryByRole("button", { name: "Reply" }),
    ).not.toBeInTheDocument();
    await user.click(within(reply).getByRole("button", { name: "Edit" }));
    const editor = within(reply).getByDisplayValue(
      "She really was — even in that wind!",
    );
    await user.clear(editor);
    await user.type(editor, "Corrected reply");
    await user.click(within(reply).getByRole("button", { name: "Save" }));
    expect(updatePhotoText).toHaveBeenCalledWith(
      familySlug,
      photoId,
      "comments",
      "reply-1",
      "Corrected reply",
    );
    await user.click(within(reply).getByRole("button", { name: "Remove" }));
    expect(removePhotoText).toHaveBeenCalledWith(
      familySlug,
      photoId,
      "comments",
      "reply-1",
    );

    await user.click(screen.getByRole("button", { name: "Reply" }));
    expect(screen.getByLabelText("Reply to Sarah Mercer")).toHaveFocus();
    await user.click(screen.getByRole("button", { name: "Cancel" }));
    await waitFor(() => {
      expect(screen.getByRole("button", { name: "Reply" })).toHaveFocus();
    });
  });

  it("uses Album-scoped love semantics", async () => {
    const user = userEvent.setup();
    renderPanel(true);
    await user.click(
      await screen.findByRole("button", { name: "Remove love · 2" }),
    );
    await waitFor(() => {
      expect(removePhotoReaction).toHaveBeenCalledWith(
        familySlug,
        photoId,
        albumId,
      );
    });
    expect(savePhotoReaction).not.toHaveBeenCalled();
  });
});
