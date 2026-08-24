import { type SyntheticEvent, useState } from "react";

import { splitTags } from "../validation/tagInput";

export function PhotoTagsForm({
  initialTags,
  pending,
  onSubmit,
}: {
  initialTags: string[];
  pending: boolean;
  onSubmit: (tags: string[]) => Promise<unknown>;
}) {
  const [tags, setTags] = useState(initialTags.join(", "));
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      await onSubmit(splitTags(tags));
      setMessage("Tags updated.");
    } catch {
      setMessage("Tags could not be updated.");
    }
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      <label htmlFor="photo-detail-tags">Tags</label>
      <input
        id="photo-detail-tags"
        value={tags}
        onChange={(event) => {
          setTags(event.target.value);
        }}
        placeholder="Holiday, Seaside"
      />
      <button type="submit" disabled={pending}>
        {pending ? "Saving…" : "Update tags"}
      </button>
      {message !== "" && (
        <p role={message.includes("could not") ? "alert" : "status"}>
          {message}
        </p>
      )}
    </form>
  );
}
