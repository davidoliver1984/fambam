import { useParams } from "react-router";

import {
  Breadcrumbs,
  Button,
  PageHeader,
  SectionHeader,
} from "@/components/ui";
import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import {
  useFamilyExportMutations,
  useFamilyExportsQuery,
} from "../hooks/useFamilyExports";
import type { FamilyExport, FamilyExportState } from "../types/familyExport";

import "./family-exports.css";

const dateTimeFormatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  hour: "2-digit",
  minute: "2-digit",
});

function scopeLabel(item: FamilyExport): string {
  switch (item.scope) {
    case "family_space_full":
      return "Family Space archive";
    case "collection":
      return "Collection archive";
    case "album":
      return "Album archive";
    case "personal":
      return "Personal archive";
  }
}

function scopeDescription(item: FamilyExport): string {
  switch (item.scope) {
    case "family_space_full":
      return "A complete, portable record of this Family Space.";
    case "collection":
      return "A portable archive of the Photos selected in a Collection.";
    case "album":
      return "A portable archive of the Photos in an Album.";
    case "personal":
      return "The family content you created and are still authorised to access.";
  }
}

function formatBytes(bytes: number | null): string | null {
  if (bytes === null) return null;
  if (bytes === 0) return "0 bytes";
  const units = ["bytes", "KB", "MB", "GB", "TB"];
  const unitIndex = Math.min(
    Math.floor(Math.log(bytes) / Math.log(1024)),
    units.length - 1,
  );
  const value = bytes / 1024 ** unitIndex;
  const maximumFractionDigits = unitIndex === 0 || value >= 10 ? 0 : 1;
  return `${new Intl.NumberFormat("en-GB", { maximumFractionDigits }).format(value)} ${units[unitIndex]}`;
}

function formatDateTime(value: string | null): string | null {
  if (value === null) return null;
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? null : dateTimeFormatter.format(date);
}

const stateLabels: Record<FamilyExportState, string> = {
  pending: "Queued",
  processing: "Preparing",
  ready: "Ready",
  failed: "Failed",
  expired: "Expired",
};

function stateExplanation(state: FamilyExportState): string {
  switch (state) {
    case "pending":
      return "Your request is queued and will be prepared in the background.";
    case "processing":
      return "Your archive is being prepared. You can leave this page and return later.";
    case "ready":
      return "Your archive is ready to download for a limited time.";
    case "failed":
      return "This archive could not be prepared. You can request a new one.";
    case "expired":
      return "This archive is no longer available. Request a fresh archive when you need one.";
  }
}

function ExportIcon({ name }: { name: "archive" | "download" | "person" }) {
  const common = {
    viewBox: "0 0 24 24",
    fill: "none",
    stroke: "currentColor",
    strokeWidth: 1.8,
    strokeLinecap: "round" as const,
    strokeLinejoin: "round" as const,
    "aria-hidden": true,
  };

  if (name === "download") {
    return (
      <svg {...common}>
        <path d="M12 3v12m0 0 4-4m-4 4-4-4" />
        <path d="M4 17v3h16v-3" />
      </svg>
    );
  }
  if (name === "person") {
    return (
      <svg {...common}>
        <circle cx="12" cy="8" r="4" />
        <path d="M4.5 21a7.5 7.5 0 0 1 15 0" />
      </svg>
    );
  }
  return (
    <svg {...common}>
      <path d="M4 7h16v13H4zM3 3h18v4H3z" />
      <path d="M9 11h6" />
    </svg>
  );
}

function ExportHistoryCard({
  item,
  downloading,
  onDownload,
}: {
  item: FamilyExport;
  downloading: boolean;
  onDownload: () => void;
}) {
  const state = item.state;
  const requested = formatDateTime(item.created_at);
  const expires = formatDateTime(item.expires_at);
  const size = formatBytes(item.byte_size);
  const ready = state === "ready";

  return (
    <article
      className="export-history-card"
      aria-labelledby={`export-${item.id}`}
    >
      <div className="export-history-card__topline">
        <div>
          <p className="export-history-card__kind">{scopeLabel(item)}</p>
          <h3 id={`export-${item.id}`}>{scopeDescription(item)}</h3>
        </div>
        <span className={`export-status export-status--${state}`}>
          <span aria-hidden="true" />
          {stateLabels[state]}
        </span>
      </div>

      <p
        className="export-history-card__state"
        role={
          state === "pending" || state === "processing" ? "status" : undefined
        }
      >
        {stateExplanation(state)}
      </p>

      <dl className="export-history-card__facts">
        {requested !== null && (
          <div>
            <dt>Requested</dt>
            <dd>{requested}</dd>
          </div>
        )}
        {item.photo_count !== null && (
          <div>
            <dt>Photos</dt>
            <dd>{item.photo_count.toLocaleString("en-GB")}</dd>
          </div>
        )}
        {size !== null && (
          <div>
            <dt>Archive size</dt>
            <dd>{size}</dd>
          </div>
        )}
        {expires !== null && (
          <div>
            <dt>{state === "expired" ? "Expired" : "Available until"}</dt>
            <dd>{expires}</dd>
          </div>
        )}
      </dl>

      {ready && (
        <div className="export-history-card__action">
          <Button variant="primary" disabled={downloading} onClick={onDownload}>
            <ExportIcon name="download" />
            {downloading ? "Authorising…" : "Download archive"}
          </Button>
          <small>Download access is checked again before it begins.</small>
        </div>
      )}
    </article>
  );
}

export function FamilyExportsPage() {
  const { familySlug = "" } = useParams();
  const familySpace = useFamilySpaceQuery(familySlug);
  const exports = useFamilyExportsQuery(familySlug, true);
  const mutations = useFamilyExportMutations(familySlug);
  const isOwner = familySpace.data?.role === "owner";
  const base = `/families/${encodeURIComponent(familySlug)}`;

  if (familySpace.isPending || exports.isPending) {
    return <p role="status">Opening your exports…</p>;
  }
  if (familySpace.isError || exports.isError) {
    return <p role="alert">Your exports could not be loaded.</p>;
  }

  return (
    <main className="exports-page" aria-labelledby="family-exports-title">
      <Breadcrumbs
        items={[{ label: "Home", to: base }, { label: "Exports" }]}
      />
      <PageHeader
        eyebrow="Your archive"
        title="Exports"
        id="family-exports-title"
        description="Create portable archives of your family memories. They are prepared securely in the background, and generated downloads expire automatically."
      />

      <section
        className="export-request-section"
        aria-labelledby="request-export-title"
      >
        <SectionHeader
          eyebrow="Portable by design"
          title="Request an export"
          id="request-export-title"
          description="Choose the archive that matches what you want to keep. We will let you know when it is ready."
        />
        <div className="export-request-grid">
          <article className="export-request-card">
            <span className="export-request-card__icon">
              <ExportIcon name="archive" />
            </span>
            <div className="export-request-card__copy">
              <p className="export-request-card__eyebrow">
                For the whole family
              </p>
              <h3>Family Space archive</h3>
              <p>
                A complete portable archive of this Family Space, available to
                its Owner under the current export policy.
              </p>
            </div>
            {isOwner ? (
              <Button
                variant="secondary"
                disabled={mutations.request.isPending}
                onClick={() => {
                  mutations.request.mutate();
                }}
              >
                {mutations.request.isPending
                  ? "Requesting…"
                  : "Request Family Space archive"}
              </Button>
            ) : (
              <p className="export-request-card__permission">
                <span aria-hidden="true">◆</span> Only the Family Space Owner
                can request this archive.
              </p>
            )}
          </article>

          <article className="export-request-card export-request-card--blue">
            <span className="export-request-card__icon">
              <ExportIcon name="person" />
            </span>
            <div className="export-request-card__copy">
              <p className="export-request-card__eyebrow">Just yours</p>
              <h3>My personal archive</h3>
              <p>
                A portable archive of family content you created and are still
                authorised to access.
              </p>
            </div>
            <Button
              variant="primary"
              disabled={mutations.requestPersonal.isPending}
              onClick={() => {
                mutations.requestPersonal.mutate();
              }}
            >
              {mutations.requestPersonal.isPending
                ? "Requesting…"
                : "Request my archive"}
            </Button>
          </article>
        </div>
        {(mutations.request.isError || mutations.requestPersonal.isError) && (
          <p className="export-page-message" role="alert">
            The export could not be requested. Please try again.
          </p>
        )}
      </section>

      <section
        className="export-history-section"
        aria-labelledby="export-history-title"
      >
        <SectionHeader
          eyebrow="Archive history"
          title="Your recent exports"
          id="export-history-title"
          description="Preparing archives refresh here automatically. Ready downloads remain available only until the date shown."
        />
        {mutations.download.isError && (
          <p className="export-page-message" role="alert">
            This download could not be authorised. It may have expired or your
            access may have changed.
          </p>
        )}
        {exports.data.length === 0 ? (
          <div className="export-empty-state">
            <span className="export-empty-state__icon">
              <ExportIcon name="archive" />
            </span>
            <h3>No exports yet</h3>
            <p>
              When you request an archive, its preparation and download status
              will appear here.
            </p>
          </div>
        ) : (
          <div className="export-history-list">
            {exports.data.map((item) => (
              <ExportHistoryCard
                key={item.id}
                item={item}
                downloading={
                  mutations.download.isPending &&
                  mutations.download.variables === item.id
                }
                onDownload={() => {
                  mutations.download.mutate(item.id, {
                    onSuccess: (authorization) => {
                      window.location.assign(authorization.url);
                    },
                  });
                }}
              />
            ))}
          </div>
        )}
      </section>
    </main>
  );
}
