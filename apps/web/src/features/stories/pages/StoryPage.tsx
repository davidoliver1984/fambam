import { useState, type ReactNode, type SyntheticEvent } from "react";
import { useNavigate, useParams } from "react-router";

import {
  Breadcrumbs,
  Button,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  EntityLink,
} from "@/components/ui";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { LoveButton } from "@/features/love/components/LoveButton";
import { familyEntityPath } from "@/navigation/familyEntityPath";

import { RichTextEditor } from "../components/RichTextEditor";
import { useStoryMutations, useStoryQuery } from "../hooks/useStories";
import {
  emptyRichTextDocument,
  richTextPlainText,
  type ActorPresentation,
  type RichTextDocument,
} from "../types/story";

import "./story.css";

function ActorName({
  actor,
  familySlug,
  children,
  ariaLabel,
}: {
  actor: ActorPresentation;
  familySlug: string;
  children?: ReactNode;
  ariaLabel?: string;
}) {
  const content = children ?? actor.display_name;
  return actor.person_id === null ? (
    <span>{content}</span>
  ) : (
    <EntityLink
      entity="person"
      aria-label={ariaLabel}
      to={familyEntityPath(familySlug, {
        type: "person",
        id: actor.person_id,
      })}
    >
      {content}
    </EntityLink>
  );
}

function MenuIcon({ children }: { children: ReactNode }) {
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {children}
    </svg>
  );
}

function formatPublished(value: string | null) {
  if (value === null) return "Publication date unavailable";
  const relative = calendarDayLabel(value);
  if (relative !== null) return `Published ${relative.toLowerCase()}`;
  return `Published ${new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "long",
    year: "numeric",
  }).format(new Date(value))}`;
}

function formatCommentTime(value: string | null) {
  if (value === null) return "Date unavailable";
  const relative = calendarDayLabel(value);
  if (relative !== null) return relative;
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(value));
}

function calendarDayLabel(value: string) {
  const date = new Date(value);
  const today = new Date();
  const dateDay = Date.UTC(date.getFullYear(), date.getMonth(), date.getDate());
  const todayDay = Date.UTC(
    today.getFullYear(),
    today.getMonth(),
    today.getDate(),
  );
  const difference = Math.round((todayDay - dateDay) / 86_400_000);
  if (difference === 0) return "Today";
  if (difference === 1) return "Yesterday";
  return null;
}

function readingTime(value: string) {
  const words = value.trim().split(/\s+/).filter(Boolean).length;
  return `${String(Math.max(1, Math.ceil(words / 200)))} minute read`;
}

export function StoryPage() {
  const { familySlug = "", storyId = "" } = useParams();
  const navigate = useNavigate();
  const viewer = useCurrentUserQuery();
  const story = useStoryQuery(familySlug, storyId);
  const actions = useStoryMutations(familySlug, storyId);
  const [comment, setComment] = useState<RichTextDocument>(
    emptyRichTextDocument,
  );
  const [editing, setEditing] = useState(false);
  const [body, setBody] = useState<RichTextDocument>(emptyRichTextDocument);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [copyStatus, setCopyStatus] = useState("");

  if (story.isPending)
    return (
      <p className="story-detail-state" role="status">
        Loading Story…
      </p>
    );
  if (story.isError)
    return (
      <p className="story-detail-state" role="alert">
        This Story is unavailable.
      </p>
    );

  const storyData = story.data;
  const storyPath = `/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(storyId)}`;
  const storiesPath = `/families/${encodeURIComponent(familySlug)}/stories`;
  const subjectPath = familyEntityPath(familySlug, story.data.subject);

  function beginEditing() {
    setBody(storyData.body);
    setEditing(true);
  }

  function submitComment(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    actions.comment.mutate(comment, {
      onSuccess: () => {
        setComment(emptyRichTextDocument());
      },
    });
  }

  async function copyLink() {
    try {
      await navigator.clipboard.writeText(window.location.href);
      setCopyStatus("Fambam link copied");
    } catch {
      setCopyStatus("The Fambam link could not be copied");
    }
  }

  return (
    <main className="story-detail-page" aria-labelledby="story-title">
      <Breadcrumbs
        items={[
          { label: "Stories", to: storiesPath },
          { label: story.data.heading },
        ]}
      />

      <div className="story-detail-layout">
        <article className="story-article">
          <div className="story-title-row">
            <div>
              <p className="ui-eyebrow story-subject">
                <span className="story-subject__label">A story about</span>{" "}
                <EntityLink
                  className="story-subject__primary"
                  entity={story.data.subject.type}
                  to={subjectPath}
                >
                  {story.data.subject.label}
                </EntityLink>
              </p>
              <h1 id="story-title">{story.data.heading}</h1>
            </div>
            <ContextMenu
              label="Story options"
              panelClassName="story-context-menu"
              placement="bottom-end"
            >
              <ButtonLink variant="ghost" to={storyPath}>
                <MenuIcon>
                  <path d="M6 3h8l4 4v14H6z" />
                  <path d="M14 3v5h5M9 13h6M9 17h6" />
                </MenuIcon>
                Open story
              </ButtonLink>
              {story.data.permissions.can_edit && (
                <Button variant="ghost" onClick={beginEditing}>
                  <MenuIcon>
                    <path d="m4 20 4.2-1 10.6-10.6a2 2 0 0 0-2.8-2.8L5.4 16.2z" />
                    <path d="m14.8 6.8 2.8 2.8" />
                  </MenuIcon>
                  Edit story
                </Button>
              )}
              <Button variant="ghost" onClick={() => void copyLink()}>
                <MenuIcon>
                  <path d="M10 13a5 5 0 0 0 7.5.5l2-2a5 5 0 0 0-7-7l-1.1 1.1" />
                  <path d="M14 11a5 5 0 0 0-7.5-.5l-2 2a5 5 0 0 0 7 7l1.1-1.1" />
                </MenuIcon>
                Copy Fambam link
              </Button>
              {story.data.permissions.can_remove && (
                <>
                  <hr className="story-menu-separator" />
                  <Button
                    className="story-menu-danger"
                    variant="ghost"
                    onClick={() => {
                      setDeleteOpen(true);
                    }}
                  >
                    <MenuIcon>
                      <path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6" />
                    </MenuIcon>
                    Delete story
                  </Button>
                </>
              )}
            </ContextMenu>
          </div>

          <div className="story-author">
            <ActorName
              actor={story.data.author}
              familySlug={familySlug}
              aria-label={`View ${story.data.author.display_name}'s profile`}
            >
              <PersonAvatar
                name={story.data.author.display_name}
                initials={story.data.author.initials}
                portraitUrl={
                  story.data.author.portrait_thumbnail_url ?? undefined
                }
                className="story-avatar"
              />
            </ActorName>
            <span>
              <ActorName actor={story.data.author} familySlug={familySlug}>
                <b>{story.data.author.display_name}</b>
              </ActorName>
              <small>
                {formatPublished(story.data.created_at)} ·{" "}
                {readingTime(richTextPlainText(story.data.body))}
              </small>
            </span>
          </div>

          {story.data.hero !== null && (
            <img
              className="story-hero"
              src={story.data.hero.url}
              alt={`Story about ${story.data.subject.label}`}
            />
          )}

          {editing ? (
            <form
              className="story-editor"
              onSubmit={(event) => {
                event.preventDefault();
                actions.update.mutate(body, {
                  onSuccess: () => {
                    setEditing(false);
                  },
                });
              }}
            >
              <RichTextEditor
                className="story-rich-text-editor"
                label="Edit Story"
                familySlug={familySlug}
                storyId={storyId}
                value={body}
                onChange={setBody}
              />
              <div className="story-editor__actions">
                <Button
                  variant="primary"
                  type="submit"
                  disabled={
                    actions.update.isPending ||
                    richTextPlainText(body).trim() === ""
                  }
                >
                  {actions.update.isPending ? "Saving…" : "Save Story"}
                </Button>
                <Button
                  onClick={() => {
                    setEditing(false);
                  }}
                >
                  Cancel
                </Button>
              </div>
              {actions.update.isError && (
                <p role="alert">The Story could not be saved.</p>
              )}
            </form>
          ) : (
            <div
              className="story-body"
              dangerouslySetInnerHTML={{ __html: story.data.body_html }}
            />
          )}

          <div className="story-end-actions">
            <LoveButton
              compact
              familySlug={familySlug}
              targetType="story"
              targetId={storyId}
            />
            {story.data.permissions.can_edit && !editing && (
              <Button aria-label="Edit Story" onClick={beginEditing}>
                <MenuIcon>
                  <path d="m4 20 4.2-1 10.6-10.6a2 2 0 0 0-2.8-2.8L5.4 16.2z" />
                  <path d="m14.8 6.8 2.8 2.8" />
                </MenuIcon>
                Edit story
              </Button>
            )}
          </div>
        </article>

        <aside
          className="story-conversation"
          aria-labelledby="story-comments-title"
        >
          <div className="story-conversation-head">
            <div>
              <p className="ui-eyebrow">Family conversation</p>
              <h2 id="story-comments-title">
                Comments <span>{story.data.comments.length}</span>
              </h2>
            </div>
            <MenuIcon>
              <path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z" />
            </MenuIcon>
          </div>

          {story.data.comments.length === 0 ? (
            <p className="story-conversation__empty">No comments yet.</p>
          ) : (
            story.data.comments.map((item) => (
              <article className="story-comment" key={item.id}>
                <ActorName
                  actor={item.author}
                  familySlug={familySlug}
                  aria-label={`View ${item.author.display_name}'s profile`}
                >
                  <PersonAvatar
                    name={item.author.display_name}
                    initials={item.author.initials}
                    portraitUrl={
                      item.author.portrait_thumbnail_url ?? undefined
                    }
                    className="story-avatar"
                  />
                </ActorName>
                <div className="story-comment__body">
                  <p className="story-comment__meta">
                    <ActorName actor={item.author} familySlug={familySlug}>
                      <b>{item.author.display_name}</b>
                    </ActorName>
                    <small>{formatCommentTime(item.created_at)}</small>
                  </p>
                  <div
                    className="story-comment__content"
                    dangerouslySetInnerHTML={{ __html: item.body_html }}
                  />
                  {item.permissions.can_remove && (
                    <button
                      className="story-comment__remove"
                      type="button"
                      disabled={actions.removeComment.isPending}
                      onClick={() => {
                        actions.removeComment.mutate(item.id);
                      }}
                    >
                      Remove
                    </button>
                  )}
                </div>
              </article>
            ))
          )}

          <form className="story-comment-input" onSubmit={submitComment}>
            <PersonAvatar
              name={viewer.data?.name ?? "You"}
              className="story-avatar story-comment-input__avatar"
            />
            <RichTextEditor
              className="story-comment-editor"
              label="Add a comment"
              familySlug={familySlug}
              storyId={storyId}
              vocabulary="comment"
              value={comment}
              onChange={setComment}
            />
            <button
              className="story-comment-input__send"
              type="submit"
              aria-label="Send comment"
              disabled={
                actions.comment.isPending ||
                richTextPlainText(comment).trim() === ""
              }
            >
              <MenuIcon>
                <path d="m9 18 6-6-6-6" />
              </MenuIcon>
            </button>
          </form>
          {actions.comment.isError && (
            <p className="story-conversation__error" role="alert">
              The comment could not be posted.
            </p>
          )}
        </aside>
      </div>

      <p className="story-copy-status" aria-live="polite">
        {copyStatus}
      </p>

      <ConfirmDialog
        open={deleteOpen}
        title={`Delete “${story.data.heading}”?`}
        confirmLabel="Delete story"
        destructive
        pending={actions.remove.isPending}
        onCancel={() => {
          setDeleteOpen(false);
        }}
        onConfirm={() => {
          actions.remove.mutate(undefined, {
            onSuccess: () => {
              void navigate(storiesPath);
            },
          });
        }}
      >
        <p>
          This Story will be removed. Its Photos remain safely in the Family
          Space unless they are deleted separately.
        </p>
        {actions.remove.isError && (
          <p role="alert">The Story could not be deleted.</p>
        )}
      </ConfirmDialog>
    </main>
  );
}
