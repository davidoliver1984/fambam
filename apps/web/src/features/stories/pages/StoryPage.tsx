import { useState, type SyntheticEvent } from "react";
import { Link, useNavigate, useParams } from "react-router";

import { LoveButton } from "@/features/love/components/LoveButton";
import { familyEntityPath } from "@/navigation/familyEntityPath";

import { useStoryMutations, useStoryQuery } from "../hooks/useStories";
import { plainTextDocument } from "../types/story";

export function StoryPage() {
  const { familySlug = "", storyId = "" } = useParams();
  const navigate = useNavigate();
  const story = useStoryQuery(familySlug, storyId);
  const actions = useStoryMutations(familySlug, storyId);
  const [comment, setComment] = useState("");
  const [editing, setEditing] = useState(false);
  const [body, setBody] = useState("");

  if (story.isPending) return <p role="status">Loading Story…</p>;
  if (story.isError) return <p role="alert">This Story is unavailable.</p>;

  function submitComment(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    actions.comment.mutate(plainTextDocument(comment), {
      onSuccess: () => {
        setComment("");
      },
    });
  }

  return (
    <main className="journey-page journey-detail" aria-labelledby="story-title">
      <p className="eyebrow">Story</p>
      <h1 id="story-title">{story.data.heading}</h1>
      <p>By {story.data.author?.name ?? "a former family member"}</p>
      {editing ? (
        <form
          onSubmit={(event) => {
            event.preventDefault();
            actions.update.mutate(plainTextDocument(body), {
              onSuccess: () => {
                setEditing(false);
              },
            });
          }}
        >
          <label htmlFor="edit-story-body">Edit Story</label>
          <textarea
            id="edit-story-body"
            rows={10}
            required
            value={body}
            onChange={(event) => {
              setBody(event.target.value);
            }}
          />
          <button
            type="submit"
            disabled={actions.update.isPending || body.trim() === ""}
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
            setBody(story.data.body_plain_text);
            setEditing(true);
          }}
        >
          Edit Story
        </button>
      )}
      <p>
        <Link to={familyEntityPath(familySlug, story.data.subject)}>
          View the {story.data.subject.type} this Story is about
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
                <small>{item.author?.name ?? "Former member"}</small>
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
          <label htmlFor="story-comment">Add a comment</label>
          <textarea
            id="story-comment"
            required
            value={comment}
            onChange={(event) => {
              setComment(event.target.value);
            }}
          />
          <button
            type="submit"
            disabled={actions.comment.isPending || comment.trim() === ""}
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
