import { useState, type SyntheticEvent } from "react";

import {
  useCreateSavedSearchMutation,
  useDeleteSavedSearchMutation,
  useSavedSearchesQuery,
  useUpdateSavedSearchMutation,
} from "../hooks/useSavedSearches";
import type { SearchCriteria } from "../types/search";

export function SavedSearchPanel({
  familySlug,
  criteria,
  onRun,
}: {
  familySlug: string;
  criteria: SearchCriteria | null;
  onRun: (id: string) => void;
}) {
  const saved = useSavedSearchesQuery(familySlug);
  const create = useCreateSavedSearchMutation(familySlug);
  const update = useUpdateSavedSearchMutation(familySlug);
  const remove = useDeleteSavedSearchMutation(familySlug);
  const [name, setName] = useState("");

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (criteria === null || name.trim() === "") return;
    create.mutate(
      { name: name.trim(), filters: criteria },
      {
        onSuccess: () => {
          setName("");
        },
      },
    );
  }

  return (
    <section aria-labelledby="saved-searches-title">
      <h2 id="saved-searches-title">Saved searches</h2>
      {criteria && (
        <form onSubmit={submit}>
          <label htmlFor="saved-search-name">Name this search</label>
          <input
            id="saved-search-name"
            value={name}
            maxLength={120}
            onChange={(event) => {
              setName(event.target.value);
            }}
          />
          <button
            type="submit"
            disabled={name.trim() === "" || create.isPending}
          >
            Save current search
          </button>
        </form>
      )}
      {(create.isError || update.isError || remove.isError) && (
        <p role="alert">The saved search could not be changed.</p>
      )}
      {saved.isPending && <p role="status">Loading saved searches…</p>}
      {saved.isError && <p role="alert">Saved searches could not be loaded.</p>}
      {saved.data && saved.data.length === 0 && <p>No saved searches yet.</p>}
      {saved.data && saved.data.length > 0 && (
        <ul>
          {saved.data.map((item) => (
            <li key={item.id}>
              <strong>{item.name}</strong>
              {item.people.length > 0 && (
                <small>
                  {item.people
                    .map((person) => person.preferred_name)
                    .join(", ")}
                </small>
              )}
              <button
                type="button"
                onClick={() => {
                  onRun(item.id);
                }}
              >
                Run
              </button>
              {criteria && (
                <button
                  type="button"
                  disabled={update.isPending}
                  onClick={() => {
                    update.mutate({
                      id: item.id,
                      input: { name: item.name, filters: criteria },
                    });
                  }}
                >
                  Replace with current search
                </button>
              )}
              <button
                type="button"
                disabled={remove.isPending}
                onClick={() => {
                  remove.mutate(item.id);
                }}
              >
                Delete
              </button>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}
