import { useState } from "react";

import {
  useFamilyExportMutations,
  useFamilyExportsQuery,
} from "@/features/exports/hooks/useFamilyExports";

import { useFamilySpaceDeletionMutations } from "../hooks/useFamilySpaceDeletionMutations";
import type { FamilySpace } from "../types/familySpace";

export function FamilySpaceDeletionPanel({
  familySpace,
}: {
  familySpace: FamilySpace;
}) {
  const [message, setMessage] = useState("");
  const deletion = useFamilySpaceDeletionMutations(familySpace.slug);
  const deletionRequested = familySpace.status === "deletion_requested";
  const exports = useFamilyExportsQuery(familySpace.slug, deletionRequested);
  const exportMutations = useFamilyExportMutations(familySpace.slug);
  const familyArchives =
    exports.data?.filter((item) => item.scope === "family_space_full") ?? [];

  if (familySpace.role !== "owner") return null;

  if (!deletionRequested) {
    return (
      <section aria-labelledby="family-deletion-title">
        <h2 id="family-deletion-title">Delete this Family Space</h2>
        <p>
          Deletion starts a grace period and can be cancelled before its
          scheduled date.
        </p>
        <button
          type="button"
          disabled={deletion.request.isPending}
          onClick={() => {
            deletion.request.mutate();
          }}
        >
          Request Family Space deletion
        </button>
        {deletion.request.isError && (
          <p role="alert">The deletion request could not be started.</p>
        )}
      </section>
    );
  }

  const scheduled = familySpace.deletion?.scheduled_at;

  return (
    <section aria-labelledby="family-deletion-title">
      <h2 id="family-deletion-title">Family Space deletion scheduled</h2>
      <p>
        {scheduled === null || scheduled === undefined
          ? "This Family Space is in its deletion grace period."
          : `This Family Space is scheduled for permanent deletion on ${new Date(scheduled).toLocaleDateString()}.`}
      </p>
      <aside aria-labelledby="download-family-archive-title">
        <h3 id="download-family-archive-title">Download your family archive</h3>
        <p>
          Create a private archive before deletion if you want to retain a
          portable copy. Deletion remains scheduled whether or not you create
          one.
        </p>
        <button
          type="button"
          disabled={exportMutations.request.isPending}
          onClick={() => {
            exportMutations.request.mutate();
          }}
        >
          Create family archive
        </button>
        {exportMutations.request.isError && (
          <p role="alert">The family archive could not be requested.</p>
        )}
        {exportMutations.download.isError && (
          <p role="alert">The archive download could not be authorised.</p>
        )}
        {exports.isPending ? (
          <p role="status">Loading family archives…</p>
        ) : exports.isError ? (
          <p role="alert">Family archives could not be loaded.</p>
        ) : familyArchives.length === 0 ? (
          <p>No family archive has been created during this grace period.</p>
        ) : (
          <ul>
            {familyArchives.map((item) => (
              <li key={item.id}>
                Family archive: {item.state}
                {item.state === "ready" && (
                  <button
                    type="button"
                    disabled={exportMutations.download.isPending}
                    onClick={() => {
                      exportMutations.download.mutate(item.id, {
                        onSuccess: (authorization) => {
                          window.location.assign(authorization.url);
                        },
                      });
                    }}
                  >
                    Download archive
                  </button>
                )}
              </li>
            ))}
          </ul>
        )}
      </aside>
      <button
        type="button"
        disabled={deletion.cancel.isPending}
        onClick={() => {
          deletion.cancel.mutate(undefined, {
            onSuccess: () => {
              setMessage("Family Space deletion cancelled.");
            },
          });
        }}
      >
        Cancel Family Space deletion
      </button>
      {deletion.cancel.isError && (
        <p role="alert">The deletion request could not be cancelled.</p>
      )}
      {message !== "" && <p role="status">{message}</p>}
    </section>
  );
}
