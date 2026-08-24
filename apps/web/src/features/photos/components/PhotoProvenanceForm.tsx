import { type SyntheticEvent, useState } from "react";

import type { Person } from "@/features/people/types/person";

import type {
  PhotoProvenanceInput,
  PhotoProvenanceProposal,
  PhotoProvenanceRole,
} from "../types/photo";

export function PhotoProvenanceForm({
  people,
  pending,
  onSubmit,
}: {
  people: Person[];
  pending: boolean;
  onSubmit: (input: PhotoProvenanceInput) => Promise<PhotoProvenanceProposal>;
}) {
  const [role, setRole] = useState<PhotoProvenanceRole>("photographer");
  const [kind, setKind] = useState<"person" | "text" | "clear">("person");
  const [personId, setPersonId] = useState("");
  const [description, setDescription] = useState("");
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const input: PhotoProvenanceInput = { role };
    if (kind === "person") input.person_id = personId;
    if (kind === "text") input.description = description.trim();
    if (kind === "clear") input.clears_claim = true;

    try {
      const result = await onSubmit(input);
      setMessage(
        result.status === "approved"
          ? "Provenance confirmed."
          : "Provenance proposal submitted for review.",
      );
      setDescription("");
    } catch {
      setMessage("The provenance change could not be submitted.");
    }
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      <label htmlFor="provenance-role">Provenance role</label>
      <select
        id="provenance-role"
        value={role}
        onChange={(event) => {
          setRole(toRole(event.target.value));
        }}
      >
        <option value="photographer">Photographer</option>
        <option value="scanner">Scanner or digitiser</option>
        <option value="physical_owner">Original physical owner</option>
      </select>
      <label htmlFor="provenance-kind">Value type</label>
      <select
        id="provenance-kind"
        value={kind}
        onChange={(event) => {
          setKind(toKind(event.target.value));
        }}
      >
        <option value="person">Person in the archive</option>
        <option value="text">Free-text fallback</option>
        <option value="clear">Clear this claim</option>
      </select>
      {kind === "person" && (
        <>
          <label htmlFor="provenance-person">Person</label>
          <select
            id="provenance-person"
            value={personId}
            onChange={(event) => {
              setPersonId(event.target.value);
            }}
            required
          >
            <option value="">Choose a Person</option>
            {people.map((person) => (
              <option key={person.id} value={person.id}>
                {person.preferred_name}
              </option>
            ))}
          </select>
        </>
      )}
      {kind === "text" && (
        <>
          <label htmlFor="provenance-description">Free-text value</label>
          <input
            id="provenance-description"
            value={description}
            onChange={(event) => {
              setDescription(event.target.value);
            }}
            required
          />
        </>
      )}
      <button type="submit" disabled={pending}>
        {pending ? "Submitting…" : "Submit provenance"}
      </button>
      {message !== "" && (
        <p role={message.includes("could not") ? "alert" : "status"}>
          {message}
        </p>
      )}
    </form>
  );
}

function toRole(value: string): PhotoProvenanceRole {
  if (value === "scanner" || value === "physical_owner") return value;
  return "photographer";
}

function toKind(value: string): "person" | "text" | "clear" {
  if (value === "text" || value === "clear") return value;
  return "person";
}
