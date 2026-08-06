import { useState } from "react";

import {
  useAssignAccountLinkMutation,
  useProposeAccountLinkMutation,
  useRemoveAccountLinkMutation,
  useResolveAccountLinkClaimMutation,
} from "../hooks/useAccountLinkMutations";
import {
  useAccountLinkClaimsQuery,
  useFamilyMembershipsQuery,
} from "../hooks/useAccountLinkQueries";
import type { Person } from "../types/person";

export function PersonAccountLinkPanel({
  familySlug,
  person,
}: {
  familySlug: string;
  person: Person;
}) {
  const manager = person.permissions.can_manage_account_link;
  const claimsQuery = useAccountLinkClaimsQuery(familySlug, person.id, manager);
  const membershipsQuery = useFamilyMembershipsQuery(familySlug, manager);
  const proposeLink = useProposeAccountLinkMutation(familySlug, person.id);
  const resolveClaim = useResolveAccountLinkClaimMutation(
    familySlug,
    person.id,
  );
  const assignLink = useAssignAccountLinkMutation(familySlug, person.id);
  const removeLink = useRemoveAccountLinkMutation(familySlug, person.id);
  const [membershipId, setMembershipId] = useState("");
  const [message, setMessage] = useState("");
  const [claimSubmitted, setClaimSubmitted] = useState(false);

  async function propose() {
    setMessage("");
    try {
      await proposeLink.mutateAsync();
      setClaimSubmitted(true);
      setMessage("Your self-claim was submitted for review.");
    } catch {
      setMessage("The self-claim could not be submitted.");
    }
  }

  async function assign() {
    if (membershipId === "") return;
    setMessage("");
    try {
      await assignLink.mutateAsync(membershipId);
      setMessage("Account link saved.");
    } catch {
      setMessage("The account link could not be saved.");
    }
  }

  async function unlink() {
    setMessage("");
    try {
      await removeLink.mutateAsync();
      setMessage("Account link removed.");
    } catch {
      setMessage("The account link could not be removed.");
    }
  }

  return (
    <section aria-labelledby="account-link-title">
      <h2 id="account-link-title">Linked account</h2>
      {person.account_link ? (
        <p>
          Linked to <strong>{person.account_link.account.name}</strong>
          {person.account_link.account.is_current_user ? " (you)" : ""}.
        </p>
      ) : (
        <p>No account is linked to this Person.</p>
      )}

      {!manager &&
        person.account_link === null &&
        person.permissions.can_propose_account_link && (
          <button
            type="button"
            disabled={proposeLink.isPending || claimSubmitted}
            onClick={() => void propose()}
          >
            {claimSubmitted ? "Self-claim submitted" : "This Person is me"}
          </button>
        )}

      {manager && (
        <>
          <h3>Assign or correct account</h3>
          {membershipsQuery.isPending && (
            <p role="status">Loading memberships…</p>
          )}
          {membershipsQuery.isError && (
            <p role="alert">Memberships could not be loaded.</p>
          )}
          {membershipsQuery.isSuccess && (
            <div className="account-link-actions">
              <label htmlFor="account-membership">Family Space member</label>
              <select
                id="account-membership"
                value={membershipId}
                onChange={(event) => {
                  setMembershipId(event.target.value);
                }}
              >
                <option value="">Select a member</option>
                {membershipsQuery.data
                  .filter((membership) => membership.state === "active")
                  .map((membership) => (
                    <option key={membership.id} value={membership.id}>
                      {membership.user.name} ({membership.role})
                    </option>
                  ))}
              </select>
              <button
                type="button"
                disabled={membershipId === "" || assignLink.isPending}
                onClick={() => void assign()}
              >
                Save account link
              </button>
              {person.account_link && (
                <button
                  type="button"
                  className="secondary"
                  disabled={removeLink.isPending}
                  onClick={() => void unlink()}
                >
                  Remove account link
                </button>
              )}
            </div>
          )}

          <h3>Pending self-claims</h3>
          {claimsQuery.isPending && <p role="status">Loading self-claims…</p>}
          {claimsQuery.isError && (
            <p role="alert">Self-claims could not be loaded.</p>
          )}
          {claimsQuery.isSuccess && claimsQuery.data.length === 0 && (
            <p>There are no pending self-claims.</p>
          )}
          {claimsQuery.data?.map((claim) => (
            <div className="account-link-claim" key={claim.id}>
              <span>{claim.account.name}</span>
              <button
                type="button"
                disabled={resolveClaim.isPending}
                onClick={() => {
                  resolveClaim.mutate({
                    claimId: claim.id,
                    resolution: "approve",
                  });
                }}
              >
                Approve self-claim
              </button>
              <button
                type="button"
                className="secondary"
                disabled={resolveClaim.isPending}
                onClick={() => {
                  resolveClaim.mutate({
                    claimId: claim.id,
                    resolution: "reject",
                  });
                }}
              >
                Reject self-claim
              </button>
            </div>
          ))}
        </>
      )}
      {message !== "" && <p role="status">{message}</p>}
    </section>
  );
}
