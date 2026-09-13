import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import {
  useFamilyExportMutations,
  useFamilyExportsQuery,
} from "../hooks/useFamilyExports";
import type { FamilyExport } from "../types/familyExport";

function scopeLabel(item: FamilyExport): string {
  return item.scope === "family_space_full"
    ? "Full Family Space archive"
    : "Personal archive";
}

function sizeLabel(bytes: number | null): string {
  return bytes === null
    ? "Not available yet"
    : `${bytes.toLocaleString()} bytes`;
}

function failureLabel(reason: string | null): string {
  return reason === "generation_failed"
    ? "Archive generation failed. Please request a new one."
    : "This export could not be completed. Please request a new one.";
}

export function FamilyExportsPage() {
  const { familySlug = "" } = useParams();
  const familySpace = useFamilySpaceQuery(familySlug);
  const exports = useFamilyExportsQuery(familySlug, true);
  const mutations = useFamilyExportMutations(familySlug);
  const isOwner = familySpace.data?.role === "owner";

  if (familySpace.isPending || exports.isPending) {
    return <p role="status">Loading family exports…</p>;
  }
  if (familySpace.isError || exports.isError) {
    return <p role="alert">Family exports could not be loaded.</p>;
  }

  return (
    <main className="auth" aria-labelledby="family-exports-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="family-exports-title">Exports</h1>
      <p>
        Create a portable archive. Generated archives expire automatically and
        downloads are authorised when requested.
      </p>

      <section aria-labelledby="request-export-title">
        <h2 id="request-export-title">Request an export</h2>
        {isOwner && (
          <button
            type="button"
            disabled={mutations.request.isPending}
            onClick={() => {
              mutations.request.mutate();
            }}
          >
            Request full Family Space archive
          </button>
        )}
        <button
          type="button"
          disabled={mutations.requestPersonal.isPending}
          onClick={() => {
            mutations.requestPersonal.mutate();
          }}
        >
          Request my personal archive
        </button>
        {(mutations.request.isError || mutations.requestPersonal.isError) && (
          <p role="alert">The export could not be requested.</p>
        )}
      </section>

      <section aria-labelledby="export-status-title">
        <h2 id="export-status-title">Export status</h2>
        {mutations.download.isError && (
          <p role="alert">The export download could not be authorised.</p>
        )}
        {exports.data.length === 0 ? (
          <p>No exports have been requested.</p>
        ) : (
          <ul>
            {exports.data.map((item) => (
              <li key={item.id}>
                <h3>{scopeLabel(item)}</h3>
                <dl>
                  <dt>Status</dt>
                  <dd>{item.state}</dd>
                  <dt>Photos</dt>
                  <dd>{item.photo_count ?? "Not available yet"}</dd>
                  <dt>Size</dt>
                  <dd>{sizeLabel(item.byte_size)}</dd>
                  <dt>Expires</dt>
                  <dd>{item.expires_at ?? "Not available yet"}</dd>
                </dl>
                {item.state === "ready" && (
                  <button
                    type="button"
                    disabled={mutations.download.isPending}
                    onClick={() => {
                      mutations.download.mutate(item.id, {
                        onSuccess: (authorization) => {
                          window.location.assign(authorization.url);
                        },
                      });
                    }}
                  >
                    Download
                  </button>
                )}
                {item.state === "failed" && (
                  <p role="status">{failureLabel(item.failure_reason)}</p>
                )}
              </li>
            ))}
          </ul>
        )}
      </section>
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
