import { useState, type SyntheticEvent } from "react";

import type { Person } from "@/features/people/types/person";

type Props = {
  faceObservationId: string;
  people: Array<Pick<Person, "id" | "preferred_name">>;
  pending: boolean;
  onSubmit: (personId: string) => void;
};

export function FaceIdentityForm({
  faceObservationId,
  people,
  pending,
  onSubmit,
}: Props) {
  const [personId, setPersonId] = useState("");

  function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (personId !== "") onSubmit(personId);
  }

  return (
    <form onSubmit={submit}>
      <label htmlFor={`face-person-${faceObservationId}`}>Person</label>
      <select
        id={`face-person-${faceObservationId}`}
        required
        value={personId}
        onChange={(event) => {
          setPersonId(event.target.value);
        }}
      >
        <option value="">Choose a Person</option>
        {people.map((person) => (
          <option key={person.id} value={person.id}>
            {person.preferred_name}
          </option>
        ))}
      </select>
      <button type="submit" disabled={pending || personId === ""}>
        {pending ? "Saving…" : "Propose identity"}
      </button>
    </form>
  );
}
