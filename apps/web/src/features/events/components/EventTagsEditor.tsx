import { useState, type KeyboardEvent, type SyntheticEvent } from "react";

import { useSearchSuggestionsQuery } from "@/features/search/hooks/useArchiveSearchQuery";

import { splitEventTags } from "../validation/tagInput";

type EventTag = { id: string; label: string };

type EventTagsEditorProps = {
  familySlug: string;
  tags: EventTag[];
  canEdit: boolean;
  pending: boolean;
  saveError: boolean;
  onSave: (tags: string[]) => Promise<unknown>;
};

function mergeLabels(current: string[], additions: string[]) {
  const merged = new Map(
    current.map((label) => [label.toLocaleLowerCase(), label]),
  );
  for (const label of additions) {
    if (merged.size === 25) break;
    merged.set(label.toLocaleLowerCase(), label);
  }
  return [...merged.values()];
}

export function EventTagsEditor({
  familySlug,
  tags,
  canEdit,
  pending,
  saveError,
  onSave,
}: EventTagsEditorProps) {
  const initialLabels = tags.map((tag) => tag.label);
  const [editing, setEditing] = useState(false);
  const [labels, setLabels] = useState(initialLabels);
  const [draft, setDraft] = useState("");
  const suggestions = useSearchSuggestionsQuery(
    familySlug,
    "tags",
    draft.trim(),
    editing,
  );

  const addDraft = () => {
    setLabels((current) => mergeLabels(current, splitEventTags(draft)));
    setDraft("");
  };
  const submit = async (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    const nextLabels = mergeLabels(labels, splitEventTags(draft));
    try {
      await onSave(nextLabels);
      setDraft("");
      setEditing(false);
    } catch {
      // The mutation owns the user-visible error state; keep the editor open.
    }
  };
  const keyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === "Enter" || event.key === ",") {
      event.preventDefault();
      addDraft();
    }
  };

  if (tags.length === 0 && !canEdit) return null;

  return (
    <div className="event-tags" aria-label="Event tags">
      <div className="event-tags__pills">
        {(editing ? labels : initialLabels).map((label) =>
          editing ? (
            <button
              className="event-tag event-tag--removable"
              key={label.toLocaleLowerCase()}
              type="button"
              aria-label={`Remove ${label}`}
              onClick={() => {
                setLabels((current) =>
                  current.filter((item) => item !== label),
                );
              }}
            >
              {label} <span aria-hidden="true">×</span>
            </button>
          ) : (
            <span className="event-tag" key={label.toLocaleLowerCase()}>
              {label}
            </span>
          ),
        )}
        {canEdit && !editing && (
          <button
            className="event-tag event-tag--add"
            type="button"
            onClick={() => {
              setLabels(initialLabels);
              setEditing(true);
            }}
          >
            <span aria-hidden="true">＋</span> Add tag
          </button>
        )}
      </div>
      {editing && (
        <form
          className="event-tags__editor"
          onSubmit={(event) => {
            void submit(event);
          }}
        >
          <label htmlFor="event-tag-input">Add or reuse a tag</label>
          <div className="event-tags__input-row">
            <input
              id="event-tag-input"
              value={draft}
              maxLength={80}
              placeholder="Start typing a tag…"
              onChange={(event) => {
                setDraft(event.target.value);
              }}
              onKeyDown={keyDown}
            />
            <button
              type="button"
              disabled={draft.trim() === "" || labels.length >= 25}
              onClick={addDraft}
            >
              Add
            </button>
          </div>
          {suggestions.data !== undefined && suggestions.data.length > 0 && (
            <ul
              className="event-tags__suggestions"
              aria-label="Tag suggestions"
            >
              {suggestions.data
                .filter(
                  (suggestion) =>
                    !labels.some(
                      (label) =>
                        label.toLocaleLowerCase() ===
                        suggestion.label.toLocaleLowerCase(),
                    ),
                )
                .slice(0, 6)
                .map((suggestion) => (
                  <li key={suggestion.id}>
                    <button
                      type="button"
                      onClick={() => {
                        setLabels((current) =>
                          mergeLabels(current, [suggestion.label]),
                        );
                        setDraft("");
                      }}
                    >
                      {suggestion.label}
                    </button>
                  </li>
                ))}
            </ul>
          )}
          {suggestions.isError && (
            <p role="alert">Existing tags could not be loaded.</p>
          )}
          <div className="event-tags__actions">
            <button type="submit" disabled={pending}>
              {pending ? "Saving…" : "Save tags"}
            </button>
            <button
              type="button"
              disabled={pending}
              onClick={() => {
                setLabels(initialLabels);
                setDraft("");
                setEditing(false);
              }}
            >
              Cancel
            </button>
          </div>
          {saveError && <p role="alert">Event tags could not be saved.</p>}
        </form>
      )}
    </div>
  );
}
