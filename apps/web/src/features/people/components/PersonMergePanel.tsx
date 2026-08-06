import { type SyntheticEvent, useState } from "react";

import { toAppError } from "@/api/errors";

import {
  useMergePersonMutation,
  useProposePersonMergeMutation,
  useResolvePersonMergeProposalMutation,
  useReversePersonMergeMutation,
} from "../hooks/usePersonMergeMutations";
import {
  usePersonMergeProposalsQuery,
  usePersonMergesQuery,
} from "../hooks/usePersonMergeQueries";
import type { AccountLinkResolution, Person } from "../types/person";

type Props = { familySlug: string; person: Person; people: Person[] };

export function PersonMergePanel({ familySlug, person, people }: Props) {
  const [survivorId, setSurvivorId] = useState("");
  const [context, setContext] = useState("");
  const [linkResolution, setLinkResolution] =
    useState<AccountLinkResolution>("keep_survivor");
  const canManage = person.permissions.can_manage_merge;
  const merges = usePersonMergesQuery(familySlug, person.id, canManage);
  const proposals = usePersonMergeProposalsQuery(
    familySlug,
    person.id,
    canManage,
  );
  const merge = useMergePersonMutation(familySlug, person.id);
  const propose = useProposePersonMergeMutation(familySlug, person.id);
  const resolve = useResolvePersonMergeProposalMutation(familySlug, person.id);
  const reverse = useReversePersonMergeMutation(familySlug, person.id);
  const candidates = people.filter((candidate) => candidate.id !== person.id);
  const activeError =
    merge.error ?? propose.error ?? resolve.error ?? reverse.error;

  if (!person.permissions.can_propose_merge) return null;

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    if (survivorId === "") return;
    if (canManage) {
      await merge.mutateAsync({
        survivor_person_id: survivorId,
        account_link_resolution: linkResolution,
      });
    } else {
      await propose.mutateAsync({
        survivor_person_id: survivorId,
        context: context.trim() || null,
      });
    }
    setSurvivorId("");
    setContext("");
  }

  return (
    <section aria-labelledby="person-merge-title">
      <h2 id="person-merge-title">Possible duplicate</h2>
      <p>
        {canManage
          ? "Merge this record into the surviving Person. The original address remains as a redirect."
          : "Suggest that this record is a duplicate for an Owner or Administrator to review."}
      </p>
      {candidates.length === 0 ? (
        <p>No other People are available.</p>
      ) : (
        <form onSubmit={(event) => void submit(event)}>
          <label htmlFor="merge-survivor">Surviving Person</label>
          <select
            id="merge-survivor"
            value={survivorId}
            onChange={(event) => {
              setSurvivorId(event.target.value);
            }}
            required
          >
            <option value="">Choose a Person</option>
            {candidates.map((candidate) => (
              <option key={candidate.id} value={candidate.id}>
                {candidate.preferred_name}
              </option>
            ))}
          </select>
          {canManage ? (
            <>
              <label htmlFor="merge-link-resolution">
                If both records have account links
              </label>
              <select
                id="merge-link-resolution"
                value={linkResolution}
                onChange={(event) => {
                  setLinkResolution(
                    event.target.value as AccountLinkResolution,
                  );
                }}
              >
                <option value="keep_survivor">Keep survivor link</option>
                <option value="keep_absorbed">Keep this record’s link</option>
                <option value="remove_both">Remove both links</option>
              </select>
            </>
          ) : (
            <>
              <label htmlFor="merge-context">Why do these look alike?</label>
              <textarea
                id="merge-context"
                value={context}
                onChange={(event) => {
                  setContext(event.target.value);
                }}
              />
            </>
          )}
          <button type="submit" disabled={merge.isPending || propose.isPending}>
            {canManage ? "Merge Person" : "Submit duplicate proposal"}
          </button>
        </form>
      )}

      {(merge.isSuccess || propose.isSuccess) && (
        <p role="status">
          {canManage ? "Person merged." : "Duplicate proposal submitted."}
        </p>
      )}
      {activeError && <p role="alert">{toAppError(activeError).message}</p>}

      {canManage && (
        <>
          <h3>Pending duplicate proposals</h3>
          {proposals.isPending && <p role="status">Loading proposals…</p>}
          {proposals.isError && (
            <p role="alert">Duplicate proposals could not be loaded.</p>
          )}
          {proposals.isSuccess && proposals.data.length === 0 && (
            <p>No pending duplicate proposals.</p>
          )}
          {proposals.data?.map((proposal) => (
            <article key={proposal.id}>
              <p>
                Merge {proposal.absorbed.preferred_name} into{" "}
                {proposal.survivor.preferred_name}
              </p>
              {proposal.context && <p>{proposal.context}</p>}
              <button
                type="button"
                disabled={resolve.isPending}
                onClick={() => {
                  resolve.mutate({
                    proposalId: proposal.id,
                    resolution: "approve",
                    accountLinkResolution: linkResolution,
                  });
                }}
              >
                Approve duplicate merge
              </button>
              <button
                type="button"
                disabled={resolve.isPending}
                onClick={() => {
                  resolve.mutate({
                    proposalId: proposal.id,
                    resolution: "reject",
                  });
                }}
              >
                Reject duplicate merge
              </button>
            </article>
          ))}

          <h3>Merge history</h3>
          {merges.isPending && <p role="status">Loading merge history…</p>}
          {merges.isError && (
            <p role="alert">Merge history could not be loaded.</p>
          )}
          {merges.isSuccess && merges.data.length === 0 && (
            <p>No merge history for this Person.</p>
          )}
          {merges.data?.map((item) => (
            <article key={item.id}>
              <p>
                {item.absorbed.preferred_name} merged into{" "}
                {item.survivor.preferred_name} —{" "}
                {item.status.replaceAll("_", " ")}
              </p>
              {item.status !== "reversed" && (
                <button
                  type="button"
                  disabled={reverse.isPending}
                  onClick={() => {
                    reverse.mutate(item.id);
                  }}
                >
                  Reverse merge
                </button>
              )}
            </article>
          ))}
        </>
      )}
    </section>
  );
}
