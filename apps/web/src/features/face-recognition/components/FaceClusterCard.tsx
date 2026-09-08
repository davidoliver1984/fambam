import { useState, type SyntheticEvent } from "react";

import type { Person } from "@/features/people/types/person";

import type {
  FaceCluster,
  FaceSuggestionPreview,
} from "../types/faceRecognition";
import { FaceIdentityForm } from "./FaceIdentityForm";
import { FaceObservationPreview } from "./FaceObservationPreview";

type Props = {
  cluster: FaceCluster;
  people: Array<Pick<Person, "id" | "preferred_name">>;
  canResolve: boolean;
  pending: boolean;
  selected: boolean;
  onSelectionChange: (selected: boolean) => void;
  onName: (personId: string) => void;
  onProposeFace: (faceObservationId: string, personId: string) => void;
  onFindSuggestions: (faceObservationId: string) => void;
  recognitionProcessingEnabled: boolean;
  suggestionErrorObservationId: string | null;
  suggestionErrorMessage: string | null;
  suggestion: FaceSuggestionPreview | null;
  onSplit: (groups: string[][]) => void;
};

export function FaceClusterCard({
  cluster,
  people,
  canResolve,
  pending,
  selected,
  onSelectionChange,
  onName,
  onProposeFace,
  onFindSuggestions,
  recognitionProcessingEnabled,
  suggestionErrorObservationId,
  suggestionErrorMessage,
  suggestion,
  onSplit,
}: Props) {
  const [personId, setPersonId] = useState("");

  function submitName(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (personId !== "") onName(personId);
  }

  const observationIds = cluster.members.map((member) => member.observation.id);
  const canSplit = canResolve && observationIds.length > 1;

  return (
    <article aria-labelledby={`face-cluster-${cluster.id}`}>
      <h2 id={`face-cluster-${cluster.id}`}>
        Face group ({cluster.members.length})
      </h2>
      {canResolve && (
        <label>
          <input
            type="checkbox"
            checked={selected}
            onChange={(event) => {
              onSelectionChange(event.target.checked);
            }}
          />
          Select group for merge
        </label>
      )}
      <ul>
        {cluster.members.map((member) => (
          <li key={member.id}>
            <FaceObservationPreview observation={member.observation} />
            <FaceIdentityForm
              faceObservationId={member.observation.id}
              people={people}
              pending={pending}
              onSubmit={(selectedPersonId) => {
                onProposeFace(member.observation.id, selectedPersonId);
              }}
            />
            <button
              type="button"
              disabled={pending || !recognitionProcessingEnabled}
              onClick={() => {
                onFindSuggestions(member.observation.id);
              }}
            >
              Find possible identities
            </button>
            {!recognitionProcessingEnabled && (
              <p>
                Recognition suggestions are not enabled for this family yet.
              </p>
            )}
            {suggestionErrorObservationId === member.observation.id &&
              suggestionErrorMessage !== null && (
                <p role="alert">{suggestionErrorMessage}</p>
              )}
            {suggestion?.observation_id === member.observation.id && (
              <div role="status">
                {suggestion.band === "none" && <p>No safe suggestion.</p>}
                {suggestion.band === "strong" && (
                  <p>A strong suggestion was added to identity review.</p>
                )}
                {suggestion.band === "shortlist" && (
                  <>
                    <p>Possible people:</p>
                    {suggestion.candidates.map((candidate) => (
                      <button
                        key={candidate.id}
                        type="button"
                        disabled={pending}
                        onClick={() => {
                          onProposeFace(member.observation.id, candidate.id);
                        }}
                      >
                        Propose {candidate.preferred_name}
                      </button>
                    ))}
                  </>
                )}
              </div>
            )}
          </li>
        ))}
      </ul>
      <form onSubmit={submitName}>
        <label htmlFor={`cluster-person-${cluster.id}`}>Name this group</label>
        <select
          id={`cluster-person-${cluster.id}`}
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
          {canResolve ? "Confirm group identity" : "Propose group identity"}
        </button>
      </form>
      {canSplit && (
        <button
          type="button"
          disabled={pending}
          onClick={() => {
            onSplit([[observationIds[0]], observationIds.slice(1)]);
          }}
        >
          Split first face into a separate group
        </button>
      )}
    </article>
  );
}
