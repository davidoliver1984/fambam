import { type SyntheticEvent, useState } from "react";

import { toAppError } from "@/api/errors";

import {
  useAddCirclePersonMutation,
  useCreateFamilyCircleMutation,
  useDeleteFamilyCircleMutation,
  useFamilyCirclesQuery,
  useRemoveCirclePersonMutation,
  useUpdateFamilyCircleMutation,
} from "../hooks/useCircleQueries";
import type { Person } from "../types/person";

export function FamilyCirclesPanel({
  familySlug,
  people,
}: {
  familySlug: string;
  people: Person[];
}) {
  const circles = useFamilyCirclesQuery(familySlug);
  const create = useCreateFamilyCircleMutation(familySlug);
  const addPerson = useAddCirclePersonMutation(familySlug);
  const removePerson = useRemoveCirclePersonMutation(familySlug);
  const update = useUpdateFamilyCircleMutation(familySlug);
  const remove = useDeleteFamilyCircleMutation(familySlug);
  const [name, setName] = useState("");
  const [circleId, setCircleId] = useState("");
  const [personId, setPersonId] = useState("");
  const [editCircleId, setEditCircleId] = useState("");
  const [editName, setEditName] = useState("");
  const [message, setMessage] = useState<string | null>(null);

  async function submitCircle(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      await create.mutateAsync({ name });
      setName("");
      setMessage("Family Circle created.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function submitPerson(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      await addPerson.mutateAsync({ circleId, personId });
      setPersonId("");
      setMessage("Person added to the Family Circle.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function submitCircleEdit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    try {
      await update.mutateAsync({
        circleId: editCircleId,
        input: { name: editName },
      });
      setEditCircleId("");
      setEditName("");
      setMessage("Family Circle renamed.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function deleteCircle(targetCircleId: string) {
    try {
      await remove.mutateAsync(targetCircleId);
      setMessage("Family Circle deleted.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function deleteCirclePerson(
    targetCircleId: string,
    targetPersonId: string,
  ) {
    try {
      await removePerson.mutateAsync({
        circleId: targetCircleId,
        personId: targetPersonId,
      });
      setMessage("Person removed from the Family Circle.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  return (
    <section aria-labelledby="circles-title">
      <h2 id="circles-title">Family Circles</h2>
      <p>Flat presentation groups for People. Circles never affect access.</p>
      {circles.isPending && <p role="status">Loading Family Circles…</p>}
      {circles.isError && (
        <p role="alert">Family Circles could not be loaded.</p>
      )}
      {circles.data?.length === 0 && <p>No Family Circles yet.</p>}
      {circles.data?.map((circle) => (
        <article key={circle.id}>
          <h3>{circle.name}</h3>
          {circle.description && <p>{circle.description}</p>}
          <button
            type="button"
            onClick={() => {
              void deleteCircle(circle.id);
            }}
          >
            Delete {circle.name}
          </button>
          <ul>
            {circle.people.map((person) => (
              <li key={person.id}>
                {person.preferred_name}
                <button
                  type="button"
                  onClick={() => {
                    void deleteCirclePerson(circle.id, person.id);
                  }}
                >
                  Remove {person.preferred_name} from {circle.name}
                </button>
              </li>
            ))}
          </ul>
        </article>
      ))}
      <form onSubmit={(event) => void submitCircle(event)}>
        <label htmlFor="circle-name">Circle name</label>
        <input
          id="circle-name"
          value={name}
          onChange={(event) => {
            setName(event.target.value);
          }}
          maxLength={120}
          required
        />
        <button type="submit" disabled={create.isPending}>
          Create Circle
        </button>
      </form>
      {circles.data && circles.data.length > 0 && (
        <form onSubmit={(event) => void submitCircleEdit(event)}>
          <label htmlFor="edit-circle">Circle to rename</label>
          <select
            id="edit-circle"
            value={editCircleId}
            onChange={(event) => {
              const selected = circles.data.find(
                (circle) => circle.id === event.target.value,
              );
              setEditCircleId(event.target.value);
              setEditName(selected?.name ?? "");
            }}
            required
          >
            <option value="">Select a Circle</option>
            {circles.data.map((circle) => (
              <option key={circle.id} value={circle.id}>
                {circle.name}
              </option>
            ))}
          </select>
          <label htmlFor="edit-circle-name">New Circle name</label>
          <input
            id="edit-circle-name"
            value={editName}
            onChange={(event) => {
              setEditName(event.target.value);
            }}
            maxLength={120}
            required
          />
          <button type="submit" disabled={update.isPending}>
            Rename Circle
          </button>
        </form>
      )}
      {circles.data && circles.data.length > 0 && people.length > 0 && (
        <form onSubmit={(event) => void submitPerson(event)}>
          <label htmlFor="circle-select">Circle</label>
          <select
            id="circle-select"
            value={circleId}
            onChange={(event) => {
              setCircleId(event.target.value);
            }}
            required
          >
            <option value="">Select a Circle</option>
            {circles.data.map((circle) => (
              <option key={circle.id} value={circle.id}>
                {circle.name}
              </option>
            ))}
          </select>
          <label htmlFor="circle-person">Person</label>
          <select
            id="circle-person"
            value={personId}
            onChange={(event) => {
              setPersonId(event.target.value);
            }}
            required
          >
            <option value="">Select a Person</option>
            {people.map((person) => (
              <option key={person.id} value={person.id}>
                {person.preferred_name}
              </option>
            ))}
          </select>
          <button type="submit" disabled={addPerson.isPending}>
            Add to Circle
          </button>
        </form>
      )}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
