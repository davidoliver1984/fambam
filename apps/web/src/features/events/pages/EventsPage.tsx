import { useMemo, useState, type SyntheticEvent } from "react";
import { Link, useParams } from "react-router";

import {
  ArchiveCard,
  ArchiveToolbar,
  Breadcrumbs,
  Button,
  ContextMenu,
  PageHeader,
  StatusPanel,
  Surface,
  ToolbarField,
} from "@/components/ui";
import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";

import {
  useCreateEventMutation,
  useDeletedEventsQuery,
  useEventsQuery,
  useRestoreEventMutation,
} from "../hooks/useEventQueries";
import type { FamilyEvent } from "../types/event";
import "./events.css";

type EventSort = "newest" | "oldest" | "updated";
type EventView = "grid" | "list";

const formatter = new Intl.DateTimeFormat("en-GB", {
  day: "numeric",
  month: "long",
  year: "numeric",
  timeZone: "UTC",
});
const formatDate = (value: string | null) =>
  value === null
    ? "Date to be added"
    : formatter.format(new Date(`${value}T00:00:00Z`));
function formatDateRange(item: FamilyEvent) {
  if (item.starts_on === null) return "Date to be added";
  if (item.ends_on === null || item.ends_on === item.starts_on)
    return formatDate(item.starts_on);
  return `${formatDate(item.starts_on)} – ${formatDate(item.ends_on)}`;
}
function eventSummary(item: FamilyEvent) {
  const presentation = item.presentation;
  if (presentation === undefined) return item.location ?? "Family event";
  const parts: Array<string | null> = [item.location];
  if (presentation.photo_count > 0)
    parts.push(
      `${String(presentation.photo_count)} photograph${presentation.photo_count === 1 ? "" : "s"}`,
    );
  if (presentation.story_count > 0)
    parts.push(
      `${String(presentation.story_count)} stor${presentation.story_count === 1 ? "y" : "ies"}`,
    );
  return parts.filter(Boolean).join(" · ") || "Family event";
}

export function EventsPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const events = useEventsQuery(familySlug);
  const canManage = ["owner", "administrator"].includes(
    family.data?.role ?? "",
  );
  const canCreate = ["owner", "administrator", "member"].includes(
    family.data?.role ?? "",
  );
  const deleted = useDeletedEventsQuery(familySlug, canManage);
  const restore = useRestoreEventMutation(familySlug);
  const create = useCreateEventMutation(familySlug);
  const [name, setName] = useState("");
  const [startsOn, setStartsOn] = useState("");
  const [query, setQuery] = useState("");
  const [sort, setSort] = useState<EventSort>("newest");
  const [view, setView] = useState<EventView>("grid");
  const [showCreate, setShowCreate] = useState(false);
  const visibleEvents = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase();
    const filtered = (events.data ?? []).filter((item) =>
      [item.name, item.location ?? "", item.description ?? ""].some((value) =>
        value.toLocaleLowerCase().includes(normalized),
      ),
    );
    return filtered.toSorted((left, right) => {
      if (sort === "updated") return right.id.localeCompare(left.id);
      const comparison = (left.starts_on ?? "9999").localeCompare(
        right.starts_on ?? "9999",
      );
      return sort === "oldest" ? comparison : -comparison;
    });
  }, [events.data, query, sort]);

  if (events.isPending)
    return <StatusPanel tone="loading" title="Loading family events…" />;
  if (events.isError)
    return (
      <StatusPanel tone="error" title="Events could not be loaded">
        <p>Please try again.</p>
      </StatusPanel>
    );
  const submit = (event: SyntheticEvent<HTMLFormElement>) => {
    event.preventDefault();
    create.mutate(
      { name: name.trim(), starts_on: startsOn || null },
      {
        onSuccess: () => {
          setName("");
          setStartsOn("");
          setShowCreate(false);
        },
      },
    );
  };

  return (
    <main className="event-index" aria-labelledby="events-title">
      <Breadcrumbs
        items={[
          { label: "Home", to: `/families/${encodeURIComponent(familySlug)}` },
          { label: "Events" },
        ]}
      />
      <PageHeader
        eyebrow="Family timeline"
        title="Events"
        id="events-title"
        description="The holidays, weddings, ordinary Sundays and big days we remember."
        actions={
          canCreate ? (
            <Button
              variant="primary"
              onClick={() => {
                setShowCreate((current) => !current);
              }}
            >
              <span aria-hidden="true">＋</span> Create event
            </Button>
          ) : undefined
        }
      />
      {showCreate && (
        <Surface as="section" className="event-create-panel">
          <h2>Create an event</h2>
          <form onSubmit={submit}>
            <label htmlFor="event-name">Name</label>
            <input
              id="event-name"
              value={name}
              onChange={(event) => {
                setName(event.target.value);
              }}
              required
            />
            <label htmlFor="event-start">Start date</label>
            <input
              id="event-start"
              type="date"
              value={startsOn}
              onChange={(event) => {
                setStartsOn(event.target.value);
              }}
            />
            <div className="ui-inline-actions">
              <Button type="submit" disabled={create.isPending}>
                {create.isPending ? "Creating…" : "Create event"}
              </Button>
              <Button
                type="button"
                variant="ghost"
                onClick={() => {
                  setShowCreate(false);
                }}
              >
                Cancel
              </Button>
            </div>
          </form>
          {create.isError && (
            <p role="alert">The Event could not be created.</p>
          )}
        </Surface>
      )}
      <ArchiveToolbar
        label="Search and filter events"
        search={
          <ToolbarField id="event-search" label="Search">
            <input
              className="ui-field-control"
              id="event-search"
              type="search"
              placeholder="Search events…"
              value={query}
              onChange={(event) => {
                setQuery(event.target.value);
              }}
            />
          </ToolbarField>
        }
      >
        <ToolbarField id="event-sort" label="Sort">
          <select
            className="ui-field-control"
            id="event-sort"
            value={sort}
            onChange={(event) => {
              setSort(event.target.value as EventSort);
            }}
          >
            <option value="newest">Newest first</option>
            <option value="oldest">Oldest first</option>
            <option value="updated">Recently added</option>
          </select>
        </ToolbarField>
        <div className="event-view-toggle" aria-label="Choose view">
          <Button
            variant={view === "grid" ? "primary" : "secondary"}
            aria-pressed={view === "grid"}
            aria-label="Grid view"
            onClick={() => {
              setView("grid");
            }}
          >
            ▦
          </Button>
          <Button
            variant={view === "list" ? "primary" : "secondary"}
            aria-pressed={view === "list"}
            aria-label="List view"
            onClick={() => {
              setView("list");
            }}
          >
            ☷
          </Button>
        </div>
      </ArchiveToolbar>
      {visibleEvents.length === 0 ? (
        <StatusPanel
          tone="empty"
          title={query === "" ? "No events yet" : "No events match your search"}
        >
          <p>
            {query === ""
              ? "Create the first event in this family timeline."
              : "Try a different word or clear the search."}
          </p>
        </StatusPanel>
      ) : (
        <div className={`event-card-grid event-card-grid--${view}`}>
          {visibleEvents.map((item) => {
            const to = `/families/${encodeURIComponent(familySlug)}/events/${item.id}`;
            const preview = item.presentation?.preview;
            return (
              <ArchiveCard
                key={item.id}
                entity="event"
                eyebrow={formatDateRange(item)}
                title={item.name}
                to={to}
                media={
                  preview === undefined || preview === null ? (
                    <div
                      className="event-card-placeholder"
                      aria-hidden="true"
                    />
                  ) : (
                    <PhotoPresentationImage
                      familySlug={familySlug}
                      photoId={preview.photo_id}
                      mediaUploadId={preview.media_upload_id}
                      fallbackTransform="thumbnail"
                      alt=""
                    />
                  )
                }
                meta={eventSummary(item)}
                actions={
                  <ContextMenu label={`Options for ${item.name}`}>
                    <Link role="menuitem" to={to}>
                      Open event
                    </Link>
                  </ContextMenu>
                }
              />
            );
          })}
        </div>
      )}
      {canManage && (
        <details className="event-removed">
          <summary>Removed events</summary>
          {deleted.isPending ? (
            <p role="status">Loading removed Events…</p>
          ) : deleted.isError ? (
            <p role="alert">Removed Events could not be loaded.</p>
          ) : deleted.data.length === 0 ? (
            <p>No Events have been removed.</p>
          ) : (
            <ul>
              {deleted.data.map((item) => (
                <li key={item.id}>
                  {item.name}{" "}
                  <Button
                    type="button"
                    variant="secondary"
                    disabled={restore.isPending}
                    onClick={() => {
                      restore.mutate(item.id);
                    }}
                  >
                    Restore
                  </Button>
                </li>
              ))}
            </ul>
          )}
        </details>
      )}
    </main>
  );
}
