import { useEffect, useRef, useState, type SyntheticEvent } from "react";

import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { HeartGlyph } from "@/features/events/components/EventGlyphs";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { EntityLink } from "@/components/ui";

import {
  usePhotoConversation,
  usePhotoConversationMutations,
} from "../hooks/usePhotoConversation";
import type {
  PhotoComment as PhotoCommentData,
  PhotoReply,
} from "../types/photoConversation";

export function PhotoLoveControl({
  familySlug,
  photoId,
  albumId,
}: {
  familySlug: string;
  photoId: string;
  albumId?: string;
}) {
  const conversation = usePhotoConversation(familySlug, photoId, albumId);
  const currentUser = useCurrentUserQuery();
  const mutations = usePhotoConversationMutations(familySlug, photoId, albumId);

  if (conversation.data === undefined || currentUser.data === undefined)
    return null;

  const loves = conversation.data.reactions.filter(
    (reaction) => reaction.reaction === "love",
  );
  const lovedByMe = loves.some(
    (reaction) => reaction.user_id === currentUser.data.id,
  );

  return (
    <button
      type="button"
      className={`photo-detail-love${lovedByMe ? " active" : ""}`}
      aria-label={`${lovedByMe ? "Remove love" : "Love this Photo"} · ${String(loves.length)}`}
      aria-pressed={lovedByMe}
      disabled={
        albumId === undefined ||
        !conversation.data.permissions.can_interact ||
        mutations.react.isPending ||
        mutations.removeReaction.isPending
      }
      onClick={() => {
        if (lovedByMe) mutations.removeReaction.mutate();
        else mutations.react.mutate("love");
      }}
    >
      <HeartGlyph />
      {loves.length}
    </button>
  );
}

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
  const currentUser = useCurrentUserQuery();
  const [comment, setComment] = useState("");

  if (conversation.isPending) return <p role="status">Loading conversation…</p>;
  if (conversation.isError)
    return <p role="alert">The conversation could not be loaded.</p>;

  const commentCount = conversation.data.comments.reduce(
    (total, item) => total + (item.is_deleted ? 0 : 1) + item.replies.length,
    0,
  );

  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (comment.trim() === "") return;
    mutations.create.mutate(
      { kind: "comments", body: comment.trim() },
      {
        onSuccess: () => {
          setComment("");
        },
      },
    );
  };

  return (
    <section
      className="photo-conversation"
      aria-labelledby="photo-conversation-title"
    >
      <h3 className="ui-subsection-title" id="photo-conversation-title">
        Conversation <span>{commentCount}</span>
      </h3>
      {conversation.data.conversation_scope === "legacy" &&
        conversation.data.comments.length > 0 && (
          <p className="photo-conversation__notice">
            This older Photo conversation is preserved as read-only.
          </p>
        )}
      <div className="photo-comment-list">
        {conversation.data.comments.length === 0 && (
          <p className="photo-conversation__empty">No comments yet.</p>
        )}
        {conversation.data.comments.map((item) => (
          <PhotoComment
            key={item.id}
            item={item}
            familySlug={familySlug}
            canReply={conversation.data.permissions.can_interact}
            pending={mutations.update.isPending || mutations.remove.isPending}
            replyPending={mutations.create.isPending}
            onUpdate={(id, body) => {
              mutations.update.mutate({ kind: "comments", id, body });
            }}
            onRemove={(id) => {
              mutations.remove.mutate({ kind: "comments", id });
            }}
            onReply={(body, onSuccess) => {
              mutations.create.mutate(
                { kind: "comments", body, parentCommentId: item.id },
                { onSuccess },
              );
            }}
          />
        ))}
      </div>
      {conversation.data.permissions.can_interact && (
        <form className="photo-comment-composer" onSubmit={submit}>
          <PersonAvatar name={currentUser.data?.name ?? "You"} />
          <label className="sr-only" htmlFor="new-photo-comment">
            Add a comment
          </label>
          <input
            id="new-photo-comment"
            value={comment}
            placeholder="Add to the conversation…"
            onChange={(event) => {
              setComment(event.target.value);
            }}
          />
          <button
            type="submit"
            aria-label="Send comment"
            disabled={mutations.create.isPending || comment.trim() === ""}
          >
            <SendGlyph />
          </button>
        </form>
      )}
    </section>
  );
}

function PhotoComment({
  item,
  familySlug,
  canReply,
  pending,
  replyPending,
  onUpdate,
  onRemove,
  onReply,
  reply = false,
}: {
  item: PhotoCommentData | PhotoReply;
  familySlug: string;
  canReply: boolean;
  pending: boolean;
  replyPending: boolean;
  onUpdate: (id: string, body: string) => void;
  onRemove: (id: string) => void;
  onReply: (body: string, onSuccess: () => void) => void;
  reply?: boolean;
}) {
  const [editing, setEditing] = useState(false);
  const [replying, setReplying] = useState(false);
  const [draft, setDraft] = useState(item.body);
  const [replyDraft, setReplyDraft] = useState("");
  const replyButton = useRef<HTMLButtonElement>(null);
  const replyEditor = useRef<HTMLTextAreaElement>(null);
  useEffect(() => {
    if (replying) replyEditor.current?.focus();
  }, [replying]);
  const author = item.author?.name ?? "Former account";
  const personPath = item.author?.person_id
    ? `/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(item.author.person_id)}`
    : null;
  const avatar = (
    <PersonAvatar
      name={author}
      initials={item.author?.initials}
      portraitUrl={item.author?.portrait_thumbnail_url ?? undefined}
    />
  );
  const authorName =
    personPath === null ? (
      <b>{author}</b>
    ) : (
      <EntityLink entity="person" to={personPath}>
        <b>{author}</b>
      </EntityLink>
    );

  return (
    <article className={`photo-comment${reply ? " photo-comment--reply" : ""}`}>
      {item.is_deleted ? (
        <span
          className="photo-comment__avatar photo-comment__avatar--deleted"
          aria-hidden="true"
        >
          —
        </span>
      ) : personPath === null ? (
        <span className="photo-comment__avatar">{avatar}</span>
      ) : (
        <EntityLink
          className="photo-comment__avatar"
          entity="person"
          to={personPath}
          aria-label={`View ${author}`}
        >
          {avatar}
        </EntityLink>
      )}
      <div>
        {!item.is_deleted && (
          <p className="photo-comment__meta">
            {authorName}
            <time dateTime={item.created_at}>
              {relativeTime(item.created_at)}
            </time>
            {item.edited_at !== null && <small>edited</small>}
          </p>
        )}
        {item.is_deleted ? (
          <p className="photo-comment__body photo-comment__tombstone">
            Comment deleted
          </p>
        ) : editing ? (
          <form
            className="photo-comment__edit ui-compact-editor"
            onSubmit={(event) => {
              event.preventDefault();
              onUpdate(item.id, draft.trim());
              setEditing(false);
            }}
          >
            <textarea
              value={draft}
              onChange={(event) => {
                setDraft(event.target.value);
              }}
            />
            <div className="photo-comment__reply-actions">
              <button
                className="photo-comment__reply-submit"
                type="submit"
                disabled={pending || draft.trim() === ""}
              >
                Save
              </button>
              <button
                className="photo-comment__reply-cancel"
                type="button"
                disabled={pending}
                onClick={() => {
                  setEditing(false);
                }}
              >
                Cancel
              </button>
            </div>
          </form>
        ) : item.body_html ? (
          <div
            className="photo-comment__body"
            dangerouslySetInnerHTML={{ __html: item.body_html }}
          />
        ) : (
          <p className="photo-comment__body">{item.body}</p>
        )}
        {!item.is_deleted &&
          (canReply ||
            item.permissions.can_edit ||
            item.permissions.can_remove) &&
          !editing && (
            <div className="photo-comment__actions">
              {canReply && !reply && (
                <button
                  ref={replyButton}
                  className="ui-inline-action"
                  type="button"
                  onClick={() => {
                    setReplying(true);
                  }}
                >
                  Reply
                </button>
              )}
              {item.permissions.can_edit && (
                <button
                  className="ui-inline-action"
                  type="button"
                  onClick={() => {
                    setEditing(true);
                  }}
                >
                  Edit
                </button>
              )}
              {item.permissions.can_remove && (
                <button
                  className="ui-inline-action"
                  type="button"
                  disabled={pending}
                  onClick={() => {
                    onRemove(item.id);
                  }}
                >
                  Remove
                </button>
              )}
            </div>
          )}
        {replying && (
          <form
            className="photo-comment__reply-form"
            onSubmit={(event) => {
              event.preventDefault();
              if (replyDraft.trim() === "") return;
              onReply(replyDraft.trim(), () => {
                setReplyDraft("");
                setReplying(false);
                requestAnimationFrame(() => replyButton.current?.focus());
              });
            }}
          >
            <label className="sr-only" htmlFor={`reply-${item.id}`}>
              Reply to {author}
            </label>
            <textarea
              ref={replyEditor}
              id={`reply-${item.id}`}
              value={replyDraft}
              placeholder={`Reply to ${author}…`}
              onChange={(event) => {
                setReplyDraft(event.target.value);
              }}
            />
            <div className="photo-comment__reply-actions">
              <button
                className="photo-comment__reply-submit"
                type="submit"
                disabled={replyPending || replyDraft.trim() === ""}
              >
                Reply
              </button>
              <button
                className="photo-comment__reply-cancel"
                type="button"
                disabled={replyPending}
                onClick={() => {
                  setReplying(false);
                  requestAnimationFrame(() => replyButton.current?.focus());
                }}
              >
                Cancel
              </button>
            </div>
          </form>
        )}
        {!reply && "replies" in item && item.replies.length > 0 && (
          <div className="photo-comment__replies">
            {item.replies.map((child) => (
              <PhotoComment
                key={child.id}
                item={child}
                familySlug={familySlug}
                canReply={false}
                pending={pending}
                replyPending={replyPending}
                onUpdate={onUpdate}
                onRemove={onRemove}
                onReply={onReply}
                reply
              />
            ))}
          </div>
        )}
      </div>
    </article>
  );
}

function relativeTime(value: string) {
  const timestamp = new Date(value);
  if (Number.isNaN(timestamp.valueOf())) return "";
  const days = Math.round((Date.now() - timestamp.valueOf()) / 86_400_000);
  if (days <= 0) return "Today";
  if (days === 1) return "Yesterday";
  if (days < 7) return `${String(days)} days ago`;
  return new Intl.DateTimeFormat(undefined, {
    day: "numeric",
    month: "short",
    year:
      timestamp.getFullYear() === new Date().getFullYear()
        ? undefined
        : "numeric",
  }).format(timestamp);
}

function SendGlyph() {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
    >
      <path d="m22 2-7 20-4-9-9-4Z" />
      <path d="M22 2 11 13" />
    </svg>
  );
}
