import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  createPhotoText,
  getPhotoConversation,
  removePhotoReaction,
  removePhotoText,
  savePhotoReaction,
  updatePhotoText,
} from "../api/photoConversationApi";
import { PhotoConversationPanel } from "./PhotoConversationPanel";

vi.mock("../api/photoConversationApi", () => ({
  createPhotoText: vi.fn(),
  getPhotoConversation: vi.fn(),
  removePhotoReaction: vi.fn(),
  removePhotoText: vi.fn(),
  savePhotoReaction: vi.fn(),
  updatePhotoText: vi.fn(),
}));

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PhotoConversationPanel
        familySlug="family-archive"
        photoId="01KB0000000000000000000000"
        albumId="01KC0000000000000000000000"
      />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotoConversation).mockResolvedValue({
    stories: [
      {
        id: "story-1",
        body: "A family day out",
        author: { id: 1, name: "David" },
        edited_at: null,
        created_at: "2026-08-24T10:00:00Z",
        permissions: { can_edit: true, can_remove: true },
      },
    ],
    comments: [],
    reactions: [{ user_id: 2, name: "Anne", reaction: "love" }],
    permissions: { can_interact: true, can_author_story: true },
    conversation_scope: "album",
    album_id: "01KC0000000000000000000000",
  });
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoConversationPanel", () => {
  it("renders archival narrative separately from comments and lightweight reactions", async () => {
    renderPanel();
    expect(
      (await screen.findAllByText("A family day out"))[0],
    ).toBeInTheDocument();
    expect(screen.getByText("No comments yet.")).toBeInTheDocument();
    expect(screen.getByText("Anne: love")).toBeInTheDocument();
    expect(screen.getByLabelText("Add an archival story")).toBeInTheDocument();
  });

  it("uses the typed mutation boundary for edits and reactions", async () => {
    const user = userEvent.setup();
    renderPanel();
    await screen.findAllByText("A family day out");
    const edit = screen.getByLabelText("Edit");
    await user.clear(edit);
    await user.type(edit, "A corrected memory");
    await user.click(screen.getByRole("button", { name: "Save edit" }));
    expect(updatePhotoText).toHaveBeenCalledWith(
      "family-archive",
      "01KB0000000000000000000000",
      "stories",
      "story-1",
      "A corrected memory",
    );
    await user.click(screen.getByRole("button", { name: "remember" }));
    expect(savePhotoReaction).toHaveBeenCalledWith(
      "family-archive",
      "01KB0000000000000000000000",
      "remember",
      "01KC0000000000000000000000",
    );
    expect(createPhotoText).not.toHaveBeenCalled();
    expect(removePhotoText).not.toHaveBeenCalled();
    expect(removePhotoReaction).not.toHaveBeenCalled();
  });

  it("keeps Guest comments and reactions while withholding archival Story authoring", async () => {
    vi.mocked(getPhotoConversation).mockResolvedValue({
      stories: [],
      comments: [],
      reactions: [],
      permissions: { can_interact: true, can_author_story: false },
      conversation_scope: "album",
      album_id: "01KC0000000000000000000000",
    });
    renderPanel();
    expect(await screen.findByLabelText("Add a comment")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "love" })).toBeInTheDocument();
    expect(
      screen.queryByLabelText("Add an archival story"),
    ).not.toBeInTheDocument();
  });
});
