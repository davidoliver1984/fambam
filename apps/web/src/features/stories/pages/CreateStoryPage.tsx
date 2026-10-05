import { useState, type SyntheticEvent } from "react";
import { useNavigate, useParams, useSearchParams } from "react-router";

import { useAlbumsQuery } from "@/features/albums/hooks/useAlbumQueries";
import { useEventsQuery } from "@/features/events/hooks/useEventQueries";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import { usePhotosQuery } from "@/features/photos/hooks/usePhotoQueries";

import { useCreateStoryMutation } from "../hooks/useStories";
import { RichTextEditor } from "../components/RichTextEditor";
import {
  emptyRichTextDocument,
  richTextPlainText,
  type RichTextDocument,
} from "../types/story";
import type { FamilyEntity } from "@/navigation/familyEntityPath";

export function CreateStoryPage() {
  const { familySlug = "" } = useParams();
  const navigate = useNavigate();
  const [search] = useSearchParams();
  const requestedType = search.get("type");
  const initialType: FamilyEntity["type"] = [
    "person",
    "photo",
    "album",
    "event",
  ].includes(requestedType ?? "")
    ? (requestedType as FamilyEntity["type"])
    : "person";
  const [type, setType] = useState<FamilyEntity["type"]>(initialType);
  const [subjectId, setSubjectId] = useState(search.get("subjectId") ?? "");
  const [body, setBody] = useState<RichTextDocument>(emptyRichTextDocument);
  const people = usePeopleQuery(familySlug);
  const photos = usePhotosQuery(familySlug, {}, type === "photo");
  const albums = useAlbumsQuery(familySlug, {}, type === "album");
  const events = useEventsQuery(familySlug, type === "event");
  const create = useCreateStoryMutation(familySlug);
  const options =
    type === "person"
      ? people.data?.map((item) => ({
          id: item.id,
          label: item.preferred_name,
        }))
      : type === "photo"
        ? photos.data?.map((item) => ({
            id: item.id,
            label: item.caption ?? item.media_upload.client_filename,
          }))
        : type === "album"
          ? albums.data?.map((item) => ({ id: item.id, label: item.name }))
          : events.data?.map((item) => ({ id: item.id, label: item.name }));

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    create.mutate(
      {
        subject_type: type,
        subject_id: subjectId,
        body,
      },
      {
        onSuccess: (story) => {
          void navigate(
            `/families/${encodeURIComponent(familySlug)}/stories/${encodeURIComponent(story.id)}`,
          );
        },
      },
    );
  }

  return (
    <main className="journey-page" aria-labelledby="create-story-title">
      <p className="eyebrow">Stories</p>
      <h1 id="create-story-title">Create a Story</h1>
      <p>
        Choose what this memory is primarily about. You can mention other People
        in the richer editor later.
      </p>
      <form onSubmit={submit}>
        <label htmlFor="story-subject-type">Story subject</label>
        <select
          id="story-subject-type"
          value={type}
          onChange={(event) => {
            setType(event.target.value as FamilyEntity["type"]);
            setSubjectId("");
          }}
        >
          <option value="person">Person</option>
          <option value="photo">Photo</option>
          <option value="album">Album</option>
          <option value="event">Event</option>
        </select>
        {type === "album" && albums.hasNextPage && (
          <button
            type="button"
            disabled={albums.isFetchingNextPage}
            onClick={() => {
              void albums.fetchNextPage();
            }}
          >
            {albums.isFetchingNextPage
              ? "Loading more albums…"
              : "Load more albums"}
          </button>
        )}
        <label htmlFor="story-subject">Choose {type}</label>
        <select
          id="story-subject"
          required
          value={subjectId}
          onChange={(event) => {
            setSubjectId(event.target.value);
          }}
        >
          <option value="">Select…</option>
          {(options ?? []).map((item) => (
            <option key={item.id} value={item.id}>
              {item.label}
            </option>
          ))}
        </select>
        <p className="story-body-label">Your Story</p>
        <RichTextEditor
          label="Your Story"
          familySlug={familySlug}
          mentionOptions={(people.data ?? []).map((person) => ({
            id: person.id,
            label: person.preferred_name,
          }))}
          value={body}
          onChange={setBody}
        />
        <button
          type="submit"
          disabled={
            create.isPending ||
            subjectId === "" ||
            richTextPlainText(body).trim() === ""
          }
        >
          Publish Story
        </button>
      </form>
      {create.isError && <p role="alert">The Story could not be created.</p>}
    </main>
  );
}
