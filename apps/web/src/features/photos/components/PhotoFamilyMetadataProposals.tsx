import {
  useResolvePhotoMetadataMutation,
  useResolvePhotoPersonMutation,
} from "../hooks/usePhotoMutations";
import {
  usePhotoMetadataProposalsQuery,
  usePhotoPersonProposalsQuery,
} from "../hooks/usePhotoQueries";

export function PhotoFamilyMetadataProposals({
  familySlug,
  photoId,
}: {
  familySlug: string;
  photoId: string;
}) {
  const metadata = usePhotoMetadataProposalsQuery(familySlug, photoId, true);
  const people = usePhotoPersonProposalsQuery(familySlug, photoId, true);
  const resolveMetadata = useResolvePhotoMetadataMutation(familySlug, photoId);
  const resolvePerson = useResolvePhotoPersonMutation(familySlug, photoId);

  if (metadata.isPending || people.isPending)
    return <p role="status">Loading family metadata proposals…</p>;
  if (metadata.isError || people.isError)
    return <p role="alert">Family metadata proposals could not be loaded.</p>;
  if (metadata.data.length === 0 && people.data.length === 0)
    return <p>There are no pending family metadata proposals.</p>;

  return (
    <ul className="proposal-list">
      {metadata.data.map((proposal) => (
        <li key={proposal.id}>
          <p>
            <strong>{proposal.field.replaceAll("_", " ")}</strong>:{" "}
            {formatMetadata(proposal)}
          </p>
          <div className="proposal-actions">
            <button
              type="button"
              disabled={resolveMetadata.isPending}
              onClick={() => {
                resolveMetadata.mutate({
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
              disabled={resolveMetadata.isPending}
              onClick={() => {
                resolveMetadata.mutate({
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
      {people.data.map((association) => (
        <li key={association.id}>
          <p>
            <strong>Person in Photo</strong>:{" "}
            {association.person.preferred_name}
          </p>
          <div className="proposal-actions">
            <button
              type="button"
              disabled={resolvePerson.isPending}
              onClick={() => {
                resolvePerson.mutate({
                  associationId: association.id,
                  resolution: "approve",
                });
              }}
            >
              Approve
            </button>
            <button
              type="button"
              className="secondary"
              disabled={resolvePerson.isPending}
              onClick={() => {
                resolvePerson.mutate({
                  associationId: association.id,
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

function formatMetadata(proposal: {
  clears_claim: boolean;
  date: { precision: string; value: string | null } | null;
  location_description: string | null;
}): string {
  if (proposal.clears_claim) return "Clear confirmed value";
  if (proposal.date !== null)
    return proposal.date.value === null
      ? proposal.date.precision
      : `${proposal.date.precision}: ${proposal.date.value}`;
  return proposal.location_description ?? "Not supplied";
}
