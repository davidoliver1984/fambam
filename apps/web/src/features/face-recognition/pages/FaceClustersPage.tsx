import { useState } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";

import { FaceClusterCard } from "../components/FaceClusterCard";
import {
  useFaceClustersQuery,
  useGenerateFaceSuggestionsMutation,
  useMergeFaceClustersMutation,
  useNameFaceClusterMutation,
  useProposeFaceIdentityMutation,
  useSplitFaceClusterMutation,
} from "../hooks/useFaceRecognition";

export function FaceClustersPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const people = usePeopleQuery(familySlug);
  const clusters = useFaceClustersQuery(familySlug);
  const proposeFace = useProposeFaceIdentityMutation(familySlug);
  const suggestions = useGenerateFaceSuggestionsMutation(familySlug);
  const nameCluster = useNameFaceClusterMutation(familySlug);
  const merge = useMergeFaceClustersMutation(familySlug);
  const split = useSplitFaceClusterMutation(familySlug);
  const [selected, setSelected] = useState<string[]>([]);

  if (family.isPending || people.isPending || clusters.isPending) {
    return <p role="status">Loading face groups…</p>;
  }
  if (family.isError || people.isError || clusters.isError) {
    return (
      <p role="alert">
        Face groups are unavailable or you are not allowed to open them.
      </p>
    );
  }

  const canResolve =
    family.data.role === "owner" || family.data.role === "administrator";
  const pending =
    proposeFace.isPending ||
    suggestions.isPending ||
    nameCluster.isPending ||
    merge.isPending ||
    split.isPending;
  const actionFailed =
    proposeFace.isError ||
    nameCluster.isError ||
    merge.isError ||
    split.isError ||
    (suggestions.isError && suggestions.processingDisabledMessage === null);

  return (
    <main className="auth people" aria-labelledby="face-clusters-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="face-clusters-title">Unknown face groups</h1>
      <p>
        These groups are machine-derived review aids. Naming a group creates
        ordinary face identity assignments for human review or confirmation.
      </p>
      {actionFailed && (
        <p role="alert">The face-group action could not be completed.</p>
      )}
      {canResolve && (
        <button
          type="button"
          disabled={pending || selected.length < 2}
          onClick={() => {
            merge.mutate(selected, {
              onSuccess: () => {
                setSelected([]);
              },
            });
          }}
        >
          Merge selected groups
        </button>
      )}
      {clusters.data.clusters.length === 0 ? (
        <p>No active unknown-face groups are available.</p>
      ) : (
        clusters.data.clusters.map((cluster) => (
          <FaceClusterCard
            key={cluster.id}
            cluster={cluster}
            people={people.data}
            canResolve={canResolve}
            pending={pending}
            selected={selected.includes(cluster.id)}
            onSelectionChange={(checked) => {
              setSelected(
                checked
                  ? [...selected, cluster.id]
                  : selected.filter((id) => id !== cluster.id),
              );
            }}
            onName={(personId) => {
              nameCluster.mutate({
                clusterId: cluster.id,
                personId,
                confirm: canResolve,
              });
            }}
            onProposeFace={(faceObservationId, personId) => {
              proposeFace.mutate({ faceObservationId, personId });
            }}
            onFindSuggestions={(faceObservationId) => {
              suggestions.mutate(faceObservationId);
            }}
            recognitionProcessingEnabled={
              clusters.data.recognition_processing_enabled
            }
            suggestionErrorObservationId={suggestions.variables ?? null}
            suggestionErrorMessage={suggestions.processingDisabledMessage}
            suggestion={suggestions.data ?? null}
            onSplit={(groups) => {
              split.mutate({ clusterId: cluster.id, groups });
            }}
          />
        ))
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}/face-recognition`}>
        Review identity suggestions
      </Link>
    </main>
  );
}
