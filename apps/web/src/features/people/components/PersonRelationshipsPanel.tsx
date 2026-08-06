import { type SyntheticEvent, useState } from "react";

import { toAppError } from "@/api/errors";

import {
  useCreateRelationshipMutation,
  useProposeRelationshipMutation,
  useRelationshipActionMutation,
  useReplaceRelationshipMutation,
  useResolveRelationshipProposalMutation,
} from "../hooks/useRelationshipMutations";
import {
  useRelationshipProposalsQuery,
  useRelationshipsQuery,
} from "../hooks/useRelationshipQueries";
import type { Person, RelationshipType } from "../types/person";

const relationshipTypes: Array<{ value: RelationshipType; label: string }> = [
  { value: "parent_of", label: "Parent of" },
  { value: "partner_of", label: "Partner of" },
  { value: "sibling_of", label: "Sibling of" },
  { value: "guardian_of", label: "Guardian of" },
  { value: "step_parent_of", label: "Step-parent of" },
  { value: "grandparent_of", label: "Grandparent of" },
  { value: "close_family_friend_of", label: "Close family friend of" },
];

export function PersonRelationshipsPanel({
  familySlug,
  person,
  people,
}: {
  familySlug: string;
  person: Person;
  people: Person[];
}) {
  const relationships = useRelationshipsQuery(familySlug, person.id);
  const proposals = useRelationshipProposalsQuery(
    familySlug,
    person.id,
    person.permissions.can_manage_relationships,
  );
  const create = useCreateRelationshipMutation(familySlug, person.id);
  const propose = useProposeRelationshipMutation(familySlug, person.id);
  const replace = useReplaceRelationshipMutation(familySlug, person.id);
  const action = useRelationshipActionMutation(familySlug, person.id);
  const resolve = useResolveRelationshipProposalMutation(familySlug, person.id);
  const [relatedPersonId, setRelatedPersonId] = useState("");
  const [type, setType] = useState<RelationshipType>("parent_of");
  const [context, setContext] = useState("");
  const [replacementId, setReplacementId] = useState("");
  const [replacementPersonId, setReplacementPersonId] = useState("");
  const [replacementType, setReplacementType] =
    useState<RelationshipType>("parent_of");
  const [replacementContext, setReplacementContext] = useState("");
  const [message, setMessage] = useState<string | null>(null);
  const mutation = person.permissions.can_manage_relationships
    ? create
    : propose;

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage(null);
    try {
      const input = {
        related_person_id: relatedPersonId,
        type,
        context: context || null,
      };
      if (person.permissions.can_manage_relationships)
        await create.mutateAsync(input);
      else await propose.mutateAsync({ action: "create", ...input });
      setRelatedPersonId("");
      setContext("");
      setMessage(
        person.permissions.can_manage_relationships
          ? "Relationship added."
          : "Relationship proposed for review.",
      );
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function handleExisting(
    relationshipId: string,
    requestedAction: "remove" | "dispute",
  ) {
    setMessage(null);
    try {
      if (person.permissions.can_manage_relationships) {
        await action.mutateAsync({ relationshipId, action: requestedAction });
        setMessage(
          requestedAction === "remove"
            ? "Relationship removed."
            : "Relationship marked disputed.",
        );
        return;
      }
      await propose.mutateAsync({
        action: requestedAction,
        relationship_id: relationshipId,
      });
      setMessage("Correction proposed for review.");
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  async function submitReplacement(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage(null);
    const input = {
      related_person_id: replacementPersonId,
      type: replacementType,
      context: replacementContext || null,
    };
    try {
      if (person.permissions.can_manage_relationships) {
        await replace.mutateAsync({ relationshipId: replacementId, input });
      } else {
        await propose.mutateAsync({
          action: "replace",
          relationship_id: replacementId,
          ...input,
        });
      }
      setReplacementId("");
      setReplacementPersonId("");
      setReplacementContext("");
      setMessage(
        person.permissions.can_manage_relationships
          ? "Relationship corrected."
          : "Relationship correction proposed for review.",
      );
    } catch (error) {
      setMessage(toAppError(error).message);
    }
  }

  return (
    <section aria-labelledby="relationships-title">
      <h2 id="relationships-title">Relationships</h2>
      {relationships.isPending && <p role="status">Loading relationships…</p>}
      {relationships.isError && (
        <p role="alert">Relationships could not be loaded.</p>
      )}
      {relationships.data?.length === 0 && (
        <p>No confirmed relationships yet.</p>
      )}
      <ul>
        {relationships.data?.map((relationship) => (
          <li key={relationship.id}>
            <strong>{relationship.other_person.preferred_name}</strong> —{" "}
            {relationship.label}
            {relationship.status === "disputed" && " (disputed)"}
            {relationship.context && <span> — {relationship.context}</span>}
            <button
              type="button"
              onClick={() => void handleExisting(relationship.id, "dispute")}
            >
              Flag disputed
            </button>
            <button
              type="button"
              onClick={() => void handleExisting(relationship.id, "remove")}
            >
              {person.permissions.can_manage_relationships
                ? "Remove"
                : "Propose removal"}
            </button>
          </li>
        ))}
      </ul>

      {person.permissions.can_propose_relationships && (
        <form onSubmit={(event) => void submit(event)}>
          <h3>
            {person.permissions.can_manage_relationships
              ? "Add relationship"
              : "Propose relationship"}
          </h3>
          <label htmlFor="relationship-person">Person</label>
          <select
            id="relationship-person"
            value={relatedPersonId}
            onChange={(event) => {
              setRelatedPersonId(event.target.value);
            }}
            required
          >
            <option value="">Select a Person</option>
            {people
              .filter((candidate) => candidate.id !== person.id)
              .map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {candidate.preferred_name}
                </option>
              ))}
          </select>
          <label htmlFor="relationship-type">Relationship</label>
          <select
            id="relationship-type"
            value={type}
            onChange={(event) => {
              setType(event.target.value as RelationshipType);
            }}
          >
            {relationshipTypes.map((option) => (
              <option key={option.value} value={option.value}>
                {option.label}
              </option>
            ))}
          </select>
          <label htmlFor="relationship-context">Context (optional)</label>
          <textarea
            id="relationship-context"
            value={context}
            onChange={(event) => {
              setContext(event.target.value);
            }}
            maxLength={2000}
          />
          <button type="submit" disabled={mutation.isPending}>
            {mutation.isPending
              ? "Saving…"
              : person.permissions.can_manage_relationships
                ? "Add relationship"
                : "Submit relationship proposal"}
          </button>
        </form>
      )}

      {person.permissions.can_propose_relationships &&
        relationships.data &&
        relationships.data.length > 0 && (
          <form onSubmit={(event) => void submitReplacement(event)}>
            <h3>
              {person.permissions.can_manage_relationships
                ? "Correct relationship"
                : "Propose relationship correction"}
            </h3>
            <label htmlFor="replacement-relationship">
              Existing relationship
            </label>
            <select
              id="replacement-relationship"
              value={replacementId}
              onChange={(event) => {
                const selected = relationships.data.find(
                  (relationship) => relationship.id === event.target.value,
                );
                setReplacementId(event.target.value);
                setReplacementPersonId(selected?.other_person.id ?? "");
              }}
              required
            >
              <option value="">Select a relationship</option>
              {relationships.data.map((relationship) => (
                <option key={relationship.id} value={relationship.id}>
                  {relationship.other_person.preferred_name} —{" "}
                  {relationship.label}
                </option>
              ))}
            </select>
            <label htmlFor="replacement-person">Corrected Person</label>
            <select
              id="replacement-person"
              value={replacementPersonId}
              onChange={(event) => {
                setReplacementPersonId(event.target.value);
              }}
              required
            >
              <option value="">Select a Person</option>
              {people
                .filter((candidate) => candidate.id !== person.id)
                .map((candidate) => (
                  <option key={candidate.id} value={candidate.id}>
                    {candidate.preferred_name}
                  </option>
                ))}
            </select>
            <label htmlFor="replacement-type">Corrected relationship</label>
            <select
              id="replacement-type"
              value={replacementType}
              onChange={(event) => {
                setReplacementType(event.target.value as RelationshipType);
              }}
            >
              {relationshipTypes.map((option) => (
                <option key={option.value} value={option.value}>
                  {option.label}
                </option>
              ))}
            </select>
            <label htmlFor="replacement-context">
              Correction context (optional)
            </label>
            <textarea
              id="replacement-context"
              value={replacementContext}
              onChange={(event) => {
                setReplacementContext(event.target.value);
              }}
              maxLength={2000}
            />
            <button
              type="submit"
              disabled={replace.isPending || propose.isPending}
            >
              {person.permissions.can_manage_relationships
                ? "Save relationship correction"
                : "Submit relationship correction"}
            </button>
          </form>
        )}

      {person.permissions.can_manage_relationships && proposals.isPending && (
        <p role="status">Loading relationship proposals…</p>
      )}
      {person.permissions.can_manage_relationships && proposals.isError && (
        <p role="alert">Relationship proposals could not be loaded.</p>
      )}
      {person.permissions.can_manage_relationships &&
        proposals.data &&
        proposals.data.length > 0 && (
          <div>
            <h3>Relationship proposals</h3>
            <ul>
              {proposals.data.map((proposal) => (
                <li key={proposal.id}>
                  {proposal.action.replace("_", " ")}{" "}
                  {proposal.type?.replaceAll("_", " ") ?? "relationship"}
                  <button
                    type="button"
                    onClick={() => {
                      resolve.mutate({
                        proposalId: proposal.id,
                        resolution: "approve",
                      });
                    }}
                  >
                    Approve relationship proposal
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      resolve.mutate({
                        proposalId: proposal.id,
                        resolution: "reject",
                      });
                    }}
                  >
                    Reject relationship proposal
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}
      {message && <p role="status">{message}</p>}
    </section>
  );
}
