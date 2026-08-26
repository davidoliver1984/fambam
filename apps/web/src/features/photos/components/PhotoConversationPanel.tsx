import { useState, type SyntheticEvent } from "react";

import {
  usePhotoConversation,
  usePhotoConversationMutations,
} from "../hooks/usePhotoConversation";
import type {
  PhotoReactionType,
  PhotoTextContent,
} from "../types/photoConversation";

export function PhotoConversationPanel({
  familySlug,
  photoId,
  albumId,
}: {
  familySlug: string;
  photoId: string;
  albumId?: string;
}) {
  const conversation = usePhotoConversation(familySlug, photoId, albumId);
  const mutations = usePhotoConversationMutations(familySlug, photoId, albumId);
  const [story, setStory] = useState("");
  const [comment, setComment] = useState("");
  if (conversation.isPending)
    return <p role="status">Loading stories and comments…</p>;
  if (conversation.isError)
    return <p role="alert">Stories and comments could not be loaded.</p>;
  const submit =
    (kind: "stories" | "comments", body: string, clear: () => void) =>
    (event: SyntheticEvent<HTMLFormElement>) => {
      event.preventDefault();
      mutations.create.mutate({ kind, body }, { onSuccess: clear });
    };
  return (
    <>
      {conversation.data.conversation_scope === "legacy" &&
        conversation.data.comments.length > 0 && (
          <p>This older Photo conversation is preserved here as read-only.</p>
        )}
      <h3>Stories</h3>
      <ContentList
        items={conversation.data.stories}
        kind="stories"
        onUpdate={(id, body) => {
          mutations.update.mutate({ kind: "stories", id, body });
        }}
        onRemove={(id) => {
          mutations.remove.mutate({ kind: "stories", id });
        }}
      />
      {conversation.data.permissions.can_author_story && (
        <form
          onSubmit={submit("stories", story, () => {
            setStory("");
          })}
        >
          <label htmlFor="new-photo-story">Add an archival story</label>
          <textarea
            id="new-photo-story"
            value={story}
            onChange={(event) => {
              setStory(event.target.value);
            }}
            required
          />
          <button type="submit">Add story</button>
        </form>
      )}
      <h3>Comments</h3>
      <ContentList
        items={conversation.data.comments}
        kind="comments"
        onUpdate={(id, body) => {
          mutations.update.mutate({ kind: "comments", id, body });
        }}
        onRemove={(id) => {
          mutations.remove.mutate({ kind: "comments", id });
        }}
      />
      {conversation.data.permissions.can_interact && (
        <form
          onSubmit={submit("comments", comment, () => {
            setComment("");
          })}
        >
          <label htmlFor="new-photo-comment">Add a comment</label>
          <textarea
            id="new-photo-comment"
            value={comment}
            onChange={(event) => {
              setComment(event.target.value);
            }}
            required
          />
          <button type="submit">Add comment</button>
        </form>
      )}
      <h3>Reactions</h3>
      <p>
        {conversation.data.reactions
          .map((item) => `${item.name}: ${item.reaction}`)
          .join(" · ") || "No reactions yet."}
      </p>
      {conversation.data.permissions.can_interact && (
        <div>
          {(["love", "smile", "laugh", "remember"] as PhotoReactionType[]).map(
            (reaction) => (
              <button
                type="button"
                key={reaction}
                onClick={() => {
                  mutations.react.mutate(reaction);
                }}
              >
                {reaction}
              </button>
            ),
          )}
          <button
            type="button"
            onClick={() => {
              mutations.removeReaction.mutate();
            }}
          >
            Remove my reaction
          </button>
        </div>
      )}
    </>
  );
}

function ContentList({
  items,
  kind,
  onUpdate,
  onRemove,
}: {
  items: PhotoTextContent[];
  kind: string;
  onUpdate: (id: string, body: string) => void;
  onRemove: (id: string) => void;
}) {
  const [drafts, setDrafts] = useState<Record<string, string>>({});
  if (items.length === 0) return <p>No {kind} yet.</p>;
  return (
    <ul>
      {items.map((item) => (
        <li key={item.id}>
          <p>{item.body}</p>
          <small>
            By {item.author?.name ?? "Former account"}
            {item.edited_at === null ? "" : " · edited"}
          </small>
          {item.permissions.can_edit && (
            <>
              <label htmlFor={`edit-${item.id}`}>Edit</label>
              <textarea
                id={`edit-${item.id}`}
                value={drafts[item.id] ?? item.body}
                onChange={(event) => {
                  setDrafts({ ...drafts, [item.id]: event.target.value });
                }}
              />
              <button
                type="button"
                onClick={() => {
                  onUpdate(item.id, drafts[item.id] ?? item.body);
                }}
              >
                Save edit
              </button>
            </>
          )}
          {item.permissions.can_remove && (
            <button
              type="button"
              onClick={() => {
                onRemove(item.id);
              }}
            >
              Remove
            </button>
          )}
        </li>
      ))}
    </ul>
  );
}
