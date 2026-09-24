import { useState } from "react";

import { Button, Dialog } from "@/components/ui";
import {
  useCollectionsQuery,
  useCreateCollectionMutation,
} from "@/features/collections/hooks/useCollections";

import { LockGlyph, SparklesGlyph } from "./EventGlyphs";

export function CollectionPickerDialog({
  open,
  familySlug,
  mode,
  sourceName,
  pending,
  onClose,
  onAdd,
}: {
  open: boolean;
  familySlug: string;
  mode: "photo" | "source";
  sourceName: string;
  pending: boolean;
  onClose: () => void;
  onAdd: (collectionId: string) => void;
}) {
  const collections = useCollectionsQuery(familySlug);
  const create = useCreateCollectionMutation(familySlug);
  const [choice, setChoice] = useState("");
  const [newName, setNewName] = useState(sourceName);
  const [createNew, setCreateNew] = useState(false);
  const availableChoice = choice || collections.data?.[0]?.id || "";
  const busy = pending || create.isPending;
  const canSubmit = createNew ? newName.trim() !== "" : availableChoice !== "";

  const add = () => {
    if (createNew) {
      const name = newName.trim();
      if (name === "") return;
      create.mutate(
        { name, description: null },
        {
          onSuccess: (collection) => {
            onAdd(collection.id);
            onClose();
          },
        },
      );
      return;
    }
    if (availableChoice === "") return;
    onAdd(availableChoice);
    onClose();
  };

  return (
    <Dialog
      open={open}
      eyebrow="Personal working set"
      title={
        mode === "photo" ? "Add to collection" : "Add photos to collection"
      }
      description={
        mode === "photo"
          ? "Choose a private Collection for this Photo."
          : `Add the current Photos from ${sourceName}. Duplicate Photo identities will only be added once.`
      }
      className="event-collection-picker"
      pending={busy}
      onClose={onClose}
    >
      <div className="event-collection-source">
        <SparklesGlyph />
        <span>
          <b>{mode === "photo" ? "1 Photo" : sourceName}</b>
          <small>
            {mode === "photo"
              ? "The original Photo stays where it is"
              : "A snapshot of the current source Photos"}
          </small>
        </span>
      </div>
      <div className="event-collection-destinations">
        <label className={!createNew ? "selected" : ""}>
          <input
            type="radio"
            name={`destination-${sourceName}`}
            checked={!createNew}
            onChange={() => {
              setCreateNew(false);
            }}
          />
          <span>
            <b>Add to existing collection</b>
            <select
              aria-label="Choose an existing collection"
              value={availableChoice}
              onChange={(event) => {
                setChoice(event.target.value);
                setCreateNew(false);
              }}
            >
              {(collections.data ?? []).map((collection) => (
                <option key={collection.id} value={collection.id}>
                  {collection.name}
                </option>
              ))}
            </select>
          </span>
        </label>
        <label className={createNew ? "selected" : ""}>
          <input
            type="radio"
            name={`destination-${sourceName}`}
            checked={createNew}
            onChange={() => {
              setCreateNew(true);
            }}
          />
          <span>
            <b>Create new collection</b>
            <input
              value={newName}
              aria-label="New collection name"
              onChange={(event) => {
                setNewName(event.target.value);
                setCreateNew(true);
              }}
            />
          </span>
        </label>
      </div>
      <div className="event-private-note">
        <LockGlyph />
        <span>
          <b>Private to you</b>
          <small>Collections are not shared with the Family Space.</small>
        </span>
      </div>
      <footer className="event-dialog-footer">
        <Button
          type="button"
          variant="secondary"
          disabled={busy}
          onClick={onClose}
        >
          Cancel
        </Button>
        <Button
          type="button"
          variant="primary"
          disabled={!canSubmit || busy}
          onClick={add}
        >
          Add {mode === "photo" ? "Photo" : "photos"}
        </Button>
      </footer>
    </Dialog>
  );
}
