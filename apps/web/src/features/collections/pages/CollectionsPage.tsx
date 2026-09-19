import { useState, type SyntheticEvent } from "react";
import { Link, useNavigate, useParams } from "react-router";

import {
  useCollectionsQuery,
  useCreateCollectionMutation,
} from "../hooks/useCollections";

export function CollectionsPage() {
  const { familySlug = "" } = useParams();
  const navigate = useNavigate();
  const collections = useCollectionsQuery(familySlug);
  const create = useCreateCollectionMutation(familySlug);
  const [name, setName] = useState("");
  const [description, setDescription] = useState("");
  const base = `/families/${encodeURIComponent(familySlug)}`;
  if (collections.isPending) return <p role="status">Loading Collections…</p>;
  if (collections.isError)
    return <p role="alert">Collections could not be loaded.</p>;
  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), description: description.trim() || null },
      {
        onSuccess: (item) => {
          void navigate(`${base}/collections/${encodeURIComponent(item.id)}`);
        },
      },
    );
  }
  return (
    <main className="journey-page" aria-labelledby="collections-title">
      <p className="eyebrow">Your archive</p>
      <h1 id="collections-title">Collections</h1>
      <p>
        Private working sets for gathering photographs before sharing or
        exporting them.
      </p>
      {collections.data.length === 0 ? (
        <p>No Collections yet.</p>
      ) : (
        <ul className="collection-grid">
          {collections.data.map((item) => (
            <li key={item.id}>
              <Link to={`${base}/collections/${encodeURIComponent(item.id)}`}>
                {item.name}
              </Link>
              {item.description && <p>{item.description}</p>}
            </li>
          ))}
        </ul>
      )}
      <section aria-labelledby="new-collection-title">
        <h2 id="new-collection-title">Create a Collection</h2>
        <form onSubmit={submit}>
          <label htmlFor="collection-name">Name</label>
          <input
            id="collection-name"
            required
            value={name}
            onChange={(event) => {
              setName(event.target.value);
            }}
          />
          <label htmlFor="collection-description">Description</label>
          <textarea
            id="collection-description"
            value={description}
            onChange={(event) => {
              setDescription(event.target.value);
            }}
          />
          <button
            type="submit"
            disabled={create.isPending || name.trim() === ""}
          >
            Create Collection
          </button>
        </form>
        {create.isError && (
          <p role="alert">The Collection could not be created.</p>
        )}
      </section>
    </main>
  );
}
