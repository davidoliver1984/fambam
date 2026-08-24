import { useResolvePhotoProvenanceMutation } from "../hooks/usePhotoMutations";
import { usePhotoProvenanceProposalsQuery } from "../hooks/usePhotoQueries";

export function PhotoProvenanceProposals({
  familySlug,
  photoId,
}: {
  familySlug: string;
  photoId: string;
}) {
  const proposals = usePhotoProvenanceProposalsQuery(familySlug, photoId, true);
  const resolve = useResolvePhotoProvenanceMutation(familySlug, photoId);

  if (proposals.isPending)
    return <p role="status">Loading provenance proposals…</p>;
  if (proposals.isError)
    return <p role="alert">Provenance proposals could not be loaded.</p>;
  if (proposals.data.length === 0)
    return <p>There are no pending provenance proposals.</p>;

  return (
    <ul className="proposal-list">
      {proposals.data.map((proposal) => (
        <li key={proposal.id}>
          <p>
            <strong>{proposal.role.replaceAll("_", " ")}</strong>:{" "}
            {proposal.clears_claim
              ? "Clear claim"
              : (proposal.person?.preferred_name ?? proposal.description)}
          </p>
          <div className="proposal-actions">
            <button
              type="button"
              disabled={resolve.isPending}
              onClick={() => {
                resolve.mutate({
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
              disabled={resolve.isPending}
              onClick={() => {
                resolve.mutate({
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
