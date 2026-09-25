import { Link } from "react-router";

import {
  useMarkNotificationRead,
  useNotificationsQuery,
} from "@/features/notifications/hooks/useNotifications";
import type { FamilyNotification } from "@/features/notifications/types/notification";

import { notificationTarget } from "./notificationTarget";
import { PersonAvatar } from "./PersonAvatar";
import { ShellIcon } from "./ShellIcon";

type NotificationMenuProps = {
  familySlug: string;
  open: boolean;
  onClose: () => void;
};

type NotificationPresentation = {
  actorName?: string;
  actorInitials?: string;
  actorPortraitUrl?: string;
  headline?: string;
  subjectLabel?: string;
  thumbnailUrl?: string;
  messageExcerpt?: string;
};

const categoryLabels = {
  comment: "Photo conversation",
  contribution: "New photographs",
  story: "New story",
  identity: "Identity confirmed",
  export: "Export status",
  attendance: "Event invitation response",
  love: "New love",
} as const;

function presentationFor(
  item: FamilyNotification,
): NotificationPresentation & { headline: string } {
  return {
    actorName: item.presentation?.actor?.display_name,
    actorInitials: item.presentation?.actor?.initials,
    actorPortraitUrl:
      item.presentation?.actor?.portrait_thumbnail_url ?? undefined,
    headline: item.presentation?.headline ?? categoryLabels[item.category],
    subjectLabel: item.presentation?.target_label ?? undefined,
    thumbnailUrl: item.presentation?.thumbnail_url ?? undefined,
    messageExcerpt: item.presentation?.detail ?? undefined,
  };
}

function relativeTime(value: string) {
  const timestamp = new Date(value).valueOf();
  if (Number.isNaN(timestamp)) return value;
  const elapsed = Math.max(0, Date.now() - timestamp);
  const minutes = Math.floor(elapsed / 60_000);
  if (minutes < 1) return "Just now";
  if (minutes < 60)
    return `${String(minutes)} minute${minutes === 1 ? "" : "s"} ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${String(hours)} hour${hours === 1 ? "" : "s"} ago`;
  const days = Math.floor(hours / 24);
  if (days === 1) return "Yesterday";
  return `${String(days)} days ago`;
}

function firstName(value: string) {
  const withoutDemoRole = value.replace(/\s+\(Demo [^)]+\)\s*$/u, "").trim();
  return withoutDemoRole.split(/\s+/u)[0] ?? withoutDemoRole;
}

function targetNoun(item: FamilyNotification) {
  switch (item.presentation?.target?.type) {
    case "photo":
      return "photograph";
    case "album":
      return "album";
    case "story":
      return "story";
    case "person":
      return "person";
    case "event":
      return "event";
    case "family_export":
      return "family export";
    default:
      return "item";
  }
}

function contributionCount(headline: string) {
  return headline.match(/\badded\s+(\d+)\b/iu)?.[1];
}

export function NotificationMenu({
  familySlug,
  open,
  onClose,
}: NotificationMenuProps) {
  const notifications = useNotificationsQuery(familySlug);
  const read = useMarkNotificationRead(familySlug);
  const items = notifications.data ?? [];
  const unread = items.filter((item) => item.read_at === null);

  const followNotificationLink = (item: FamilyNotification) => {
    if (item.read_at === null) void read.mutateAsync(item.id);
    onClose();
  };

  return (
    open && (
      <dialog
        id="shell-notification-panel"
        open
        className="shell-notification-panel"
        aria-label="Notifications"
        onKeyDown={(event_) => {
          if (event_.key === "Escape") onClose();
        }}
      >
        <div className="shell-notification-head">
          <div>
            <p className="shell-eyebrow">Family activity</p>
            <h2>Notifications</h2>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close notifications"
          >
            <ShellIcon name="x" />
          </button>
        </div>
        {items.map((item) => {
          const presentation = presentationFor(item);
          const mediaUrl =
            item.category === "contribution"
              ? presentation.thumbnailUrl
              : presentation.actorPortraitUrl;
          const hasActor = Boolean(
            presentation.actorName ||
            presentation.actorInitials ||
            presentation.actorPortraitUrl,
          );
          const usesContentThumbnail =
            item.category === "contribution" && mediaUrl !== undefined;
          const secondary =
            presentation.messageExcerpt ?? presentation.subjectLabel;
          const actorPersonId = item.presentation?.actor?.person_id;
          const actorFirstName = firstName(presentation.actorName ?? "Someone");
          const actor = actorPersonId ? (
            <Link
              className="shell-notification-link"
              to={`/families/${familySlug}/people/${actorPersonId}`}
              onClick={() => {
                followNotificationLink(item);
              }}
            >
              {actorFirstName}
            </Link>
          ) : (
            actorFirstName
          );
          const entity = (
            <Link
              className="shell-notification-link"
              to={notificationTarget(familySlug, item)}
              onClick={() => {
                followNotificationLink(item);
              }}
            >
              {targetNoun(item)}
            </Link>
          );
          const headline = (() => {
            switch (item.category) {
              case "comment":
                return (
                  <>
                    {actor} commented on your {entity}
                  </>
                );
              case "contribution": {
                const count = contributionCount(presentation.headline);
                return (
                  <>
                    {actor} added {count ? `${count} ` : ""}
                    {count === "1" ? "photograph" : "photographs"} to your{" "}
                    {entity}
                  </>
                );
              }
              case "story":
                return (
                  <>
                    {actor} added a {entity}
                  </>
                );
              case "identity":
                return (
                  <>
                    {actor} confirmed your identity in a {entity}
                  </>
                );
              case "love":
                return (
                  <>
                    {actor} loved your {entity}
                  </>
                );
              case "attendance":
                return (
                  <>
                    {actor} responded to your {entity} invitation
                  </>
                );
              case "export":
                return <>Your {entity} status changed</>;
            }
          })();
          return (
            <div key={item.id} className="shell-notification-item">
              {usesContentThumbnail ? (
                <span className="shell-notification-media" aria-hidden="true">
                  <img src={mediaUrl} alt="" />
                </span>
              ) : hasActor ? (
                <PersonAvatar
                  name={presentation.actorName ?? ""}
                  initials={presentation.actorInitials}
                  portraitUrl={presentation.actorPortraitUrl}
                  className="shell-notification-avatar"
                />
              ) : (
                <span
                  className="shell-notification-media"
                  aria-hidden="true"
                  data-missing-read-model="true"
                />
              )}
              <span>
                <b className="shell-notification-headline">{headline}</b>
                <small>
                  {secondary ? `${secondary} · ` : ""}
                  {relativeTime(item.created_at)}
                </small>
              </span>
              {item.read_at === null && <i role="status" aria-label="Unread" />}
            </div>
          );
        })}
        <div className="shell-notification-foot">
          <button
            type="button"
            disabled={unread.length === 0 || read.isPending}
            onClick={() => {
              void Promise.all(unread.map((item) => read.mutateAsync(item.id)));
            }}
          >
            Mark all as read
          </button>
        </div>
      </dialog>
    )
  );
}
