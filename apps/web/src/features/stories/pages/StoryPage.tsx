import { useState, type SyntheticEvent } from "react";
import { Link, useNavigate, useParams } from "react-router";

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

function ActorName({
  actor,
  familySlug,
}: {
  actor: ActorPresentation;
  familySlug: string;
}) {
  return actor.person_id === null ? (
    actor.display_name
  ) : (
    <Link
      to={familyEntityPath(familySlug, {
        type: "person",
        id: actor.person_id,
      })}
    >
      {actor.display_name}
    </Link>
  );
}

export function StoryPage() {
  const { familySlug = "", storyId = "" } = useParams();
  const navigate = useNavigate();
  const story = useStoryQuery(familySlug, storyId);
  const actions = useStoryMutations(familySlug, storyId);
  const [comment, setComment] = useState<RichTextDocument>(
    emptyRichTextDocument,
  );
  const [editing, setEditing] = useState(false);
  const [body, setBody] = useState<RichTextDocument>(emptyRichTextDocument);

  if (story.isPending) return <p role="status">Loading Story…</p>;
  if (story.isError) return <p role="alert">This Story is unavailable.</p>;

  function submitComment(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    actions.comment.mutate(comment, {
      onSuccess: () => {
        setComment(emptyRichTextDocument());
      },
    });
  }

  return (
    <main className="journey-page journey-detail" aria-labelledby="story-title">
      <p className="eyebrow">Story</p>
      <h1 id="story-title">{story.data.heading}</h1>
      <p>
        By <ActorName actor={story.data.author} familySlug={familySlug} />
      </p>
      {editing ? (
        <form
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
            label="Edit Story"
            familySlug={familySlug}
            storyId={storyId}
            value={body}
            onChange={setBody}
          />
          <button
            type="submit"
            disabled={
              actions.update.isPending || richTextPlainText(body).trim() === ""
            }
          >
            Save Story
          </button>
          <button
            type="button"
            onClick={() => {
              setEditing(false);
            }}
          >
            Cancel
          </button>
        </form>
      ) : (
        <article
          className="story-body"
          dangerouslySetInnerHTML={{ __html: story.data.body_html }}
        />
      )}
      {story.data.permissions.can_edit && !editing && (
        <button
          type="button"
          onClick={() => {
            setBody(story.data.body);
            setEditing(true);
          }}
        >
          Edit Story
        </button>
      )}
      <p>
        <Link to={familyEntityPath(familySlug, story.data.subject)}>
          {story.data.subject.label}
        </Link>
      </p>
      <LoveButton
        familySlug={familySlug}
        targetType="story"
        targetId={storyId}
      />
      <section aria-labelledby="story-comments-title">
        <h2 id="story-comments-title">Family comments</h2>
        {story.data.comments.length === 0 ? (
          <p>No comments yet.</p>
        ) : (
          <ul>
            {story.data.comments.map((item) => (
              <li key={item.id}>
                <div dangerouslySetInnerHTML={{ __html: item.body_html }} />
                <small>
                  <ActorName actor={item.author} familySlug={familySlug} />
                </small>
                {item.permissions.can_remove && (
                  <button
                    type="button"
                    disabled={actions.removeComment.isPending}
                    onClick={() => {
                      actions.removeComment.mutate(item.id);
                    }}
                  >
                    Remove comment
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
        <form onSubmit={submitComment}>
          <RichTextEditor
            label="Add a comment"
            familySlug={familySlug}
            storyId={storyId}
            vocabulary="comment"
            value={comment}
            onChange={setComment}
          />
          <button
            type="submit"
            disabled={
              actions.comment.isPending ||
              richTextPlainText(comment).trim() === ""
            }
          >
            Post comment
          </button>
        </form>
        {actions.comment.isError && (
          <p role="alert">The comment could not be posted.</p>
        )}
      </section>
      {story.data.permissions.can_remove && (
        <button
          className="danger-action"
          type="button"
          disabled={actions.remove.isPending}
          onClick={() => {
            if (
              window.confirm(
                "Remove this Story? It can be restored by an authorised family administrator.",
              )
            )
              actions.remove.mutate(undefined, {
                onSuccess: () => {
                  void navigate(
                    `/families/${encodeURIComponent(familySlug)}/stories`,
                  );
                },
              });
          }}
        >
          Remove Story
        </button>
      )}
    </main>
  );
}
