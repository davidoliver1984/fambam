import { type SyntheticEvent, useState } from "react";

import type { Person } from "@/features/people/types/person";

import type { PhotoPerson } from "../types/photo";

export function PhotoPersonForm({
  people,
  pending,
  onSubmit,
}: {
  people: Person[];
  pending: boolean;
  onSubmit: (personId: string) => Promise<PhotoPerson>;
}) {
  const [personId, setPersonId] = useState("");
  const [message, setMessage] = useState("");

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      const result = await onSubmit(personId);
      setMessage(
        result.status === "approved"
          ? "Person confirmed in this Photo."
          : "Person proposal submitted for review.",
      );
      setPersonId("");
    } catch {
      setMessage("The Person proposal could not be submitted.");
    }
  }

  return (
    <form onSubmit={(event) => void submit(event)}>
      <label htmlFor="photo-person">Person appearing in this Photo</label>
      <select
        id="photo-person"
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
      <button type="submit" disabled={pending}>
        {pending ? "Submitting…" : "Submit Person proposal"}
      </button>
      {message !== "" && (
        <p role={message.includes("could not") ? "alert" : "status"}>
          {message}
        </p>
      )}
    </form>
  );
}
