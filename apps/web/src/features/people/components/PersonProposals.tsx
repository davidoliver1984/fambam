import { useResolvePersonProposalMutation } from "../hooks/usePersonMutations";
import { usePersonProposalsQuery } from "../hooks/usePersonProposalsQuery";

export function PersonProposals({
  familySlug,
  personId,
}: {
  familySlug: string;
  personId: string;
}) {
  const proposalsQuery = usePersonProposalsQuery(familySlug, personId, true);
  const resolveProposal = useResolvePersonProposalMutation(
    familySlug,
    personId,
  );

  if (proposalsQuery.isPending) return <p role="status">Loading proposals…</p>;
  if (proposalsQuery.isError)
    return <p role="alert">Proposals could not be loaded.</p>;
  if (proposalsQuery.data.length === 0)
    return <p>There are no pending proposals.</p>;

  return (
    <ul className="proposal-list">
      {proposalsQuery.data.map((proposal) => (
        <li key={proposal.id}>
          <dl>
            {Object.entries(proposal.changes).map(([field, value]) => (
              <div key={field}>
                <dt>{field.replaceAll("_", " ")}</dt>
                <dd>{formatProposalValue(value)}</dd>
              </div>
            ))}
          </dl>
          <div className="proposal-actions">
            <button
              type="button"
              disabled={resolveProposal.isPending}
              onClick={() => {
                resolveProposal.mutate({
                  proposalId: proposal.id,
                  resolution: "approve",
                });
              }}
            >
              Approve
            </button>
            <button
              type="button"
              className="secondary"
              disabled={resolveProposal.isPending}
              onClick={() => {
                resolveProposal.mutate({
                  proposalId: proposal.id,
                  resolution: "reject",
                });
              }}
            >
              Reject
            </button>
          </div>
        </li>
      ))}
    </ul>
  );
}

function formatProposalValue(value: unknown): string {
  if (value === null) return "None";
  if (typeof value === "boolean") return value ? "Yes" : "No";
  if (typeof value === "string") return value;
  if (Array.isArray(value)) return value.join(", ");
  if (typeof value === "object") {
    const date = value as { precision?: unknown; value?: unknown };
    const dateValue = typeof date.value === "string" ? date.value : "Unknown";
    const precision =
      typeof date.precision === "string" ? date.precision : "unknown";
    return `${dateValue} (${precision})`;
  }
  if (typeof value === "number" || typeof value === "bigint") {
    return value.toString();
  }
  return "Unknown";
}
