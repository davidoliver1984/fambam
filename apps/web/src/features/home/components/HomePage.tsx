import { useMutation, useQueryClient } from "@tanstack/react-query";
import { useMemo, useState, type ReactNode } from "react";
import { Link } from "react-router";

import { ButtonLink, ContextMenu, EntityLink } from "@/components/ui";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { ShellIcon } from "@/features/family-spaces/components/ShellIcon";
import type { FamilySpace } from "@/features/family-spaces/types/familySpace";
import { homeKeys, useHomeQuery } from "@/features/home/hooks/useHomeQuery";
import type {
  HomeAlbumActivity,
  HomeEngagement,
  HomePresentationPhoto,
  HomeReadModel,
  HomeStoryActivity,
} from "@/features/home/types/home";
import { removeLove, saveLove } from "@/features/love/api/loveApi";
import type { LoveTarget } from "@/features/love/types/love";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import type { PersonSummary } from "@/features/people/types/person";
import { familyEntityPath } from "@/navigation/familyEntityPath";

import "./HomePage.css";

type Props = { familySpace: FamilySpace };

const fullMemberRoles = ["owner", "administrator", "member"];

function familyBase(familySlug: string) {
  return `/families/${encodeURIComponent(familySlug)}`;
}

function storyPath(familySlug: string, storyId: string) {
  return `${familyBase(familySlug)}/stories/${encodeURIComponent(storyId)}`;
}

function albumPath(familySlug: string, albumId: string) {
  return `${familyBase(familySlug)}/albums/${encodeURIComponent(albumId)}`;
}

function photoPath(familySlug: string, photoId: string) {
  return `${familyBase(familySlug)}/photos/${encodeURIComponent(photoId)}`;
}

function relativeTime(value: string) {
  const elapsed = Date.now() - new Date(value).getTime();
  const minutes = Math.max(0, Math.floor(elapsed / 60_000));
  if (minutes < 1) return "Just now";
  if (minutes < 60)
    return `${String(minutes)} ${minutes === 1 ? "minute" : "minutes"} ago`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24)
    return `${String(hours)} ${hours === 1 ? "hour" : "hours"} ago`;
  const days = Math.floor(hours / 24);
  if (days === 1) return "Yesterday";
  if (days < 7)
    return new Intl.DateTimeFormat("en-GB", { weekday: "long" }).format(
      new Date(value),
    );
  return new Intl.DateTimeFormat("en-GB", {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(value));
}

function truncateWords(value: string, limit: number) {
  const words = value.trim().split(/\s+/);
  if (words.length <= limit) return value;
  return `${words.slice(0, limit).join(" ")}…`;
}

function greetingFor(timeZone: string, now = new Date()) {
  let hour = now.getHours();
  try {
    hour = Number(
      new Intl.DateTimeFormat("en-GB", {
        hour: "numeric",
        hourCycle: "h23",
        timeZone,
      }).format(now),
    );
  } catch {
    // Fall back to the browser's local time for an invalid legacy time zone.
  }

  if (hour < 12) return "Good morning";
  if (hour < 18) return "Good afternoon";
  return "Good evening";
}

function HomePhoto({
  photo,
  className,
}: {
  photo: HomePresentationPhoto;
  className?: string;
}) {
  return (
    <img
      className={className}
      src={photo.presentation.url}
      alt={photo.alt ?? ""}
    />
  );
}

function HomeLoveButton({
  familySlug,
  targetType,
  targetId,
  engagement,
}: {
  familySlug: string;
  targetType: LoveTarget;
  targetId: string;
  engagement: HomeEngagement;
}) {
  const client = useQueryClient();
  const homeKey = homeKeys.detail(familySlug);
  const mutation = useMutation({
    mutationFn: async (loved: boolean) => {
      if (loved) {
        await removeLove(familySlug, targetType, targetId);

        return {
          love_count: Math.max(0, engagement.love_count - 1),
          loved_by_me: false,
        };
      }

      const summary = await saveLove(familySlug, targetType, targetId);
      return {
        love_count: summary.count,
        loved_by_me: summary.loved_by_me,
      };
    },
    onSuccess: (summary) => {
      client.setQueryData<HomeReadModel>(homeKey, (current) => {
        if (current === undefined) return current;

        return {
          ...current,
          activity: current.activity.map((activity) => {
            const matches =
              targetType === "album"
                ? activity.action_type === "photos_added_to_album" &&
                  activity.album.id === targetId
                : activity.action_type === "story_added" &&
                  activity.story.id === targetId;
            return matches
              ? {
                  ...activity,
                  engagement: { ...activity.engagement, ...summary },
                }
              : activity;
          }),
        };
      });
    },
    onSettled: () =>
      client.invalidateQueries({ queryKey: homeKey, refetchType: "none" }),
  });
  const loved = engagement.loved_by_me;
  const count = engagement.love_count;

  return (
    <>
      <button
        type="button"
        className={`home-engagement${loved ? " loved" : ""}`}
        aria-label={`${loved ? "Remove love" : "Love"} · ${String(count)}`}
        aria-pressed={loved}
        disabled={mutation.isPending}
        onClick={() => {
          mutation.mutate(loved);
        }}
      >
        <ShellIcon name="heart" fill={loved ? "currentColor" : "none"} />
        <span>{count}</span>
      </button>
      {mutation.isError && (
        <span className="home-visually-hidden" role="alert">
          Your response could not be saved.
        </span>
      )}
    </>
  );
}

function FeedItemMenu({
  entity,
  path,
}: {
  entity: "album" | "story";
  path: string;
}) {
  const [copyStatus, setCopyStatus] = useState("");
  return (
    <>
      <ContextMenu label="Feed item options" placement="bottom-end">
        <EntityLink entity={entity} to={path}>
          <ShellIcon name={entity === "album" ? "image" : "file-text"} />
          View {entity}
        </EntityLink>
        <button
          type="button"
          onClick={() => {
            const url = new URL(path, window.location.origin).toString();
            void navigator.clipboard
              .writeText(url)
              .then(() => {
                setCopyStatus("Link copied");
              })
              .catch(() => {
                setCopyStatus("Link could not be copied");
              });
          }}
        >
          <ShellIcon name="link" />
          Copy link
        </button>
      </ContextMenu>
      <span className="home-visually-hidden" role="status">
        {copyStatus}
      </span>
    </>
  );
}

function Actor({
  familySlug,
  activity,
  action,
}: {
  familySlug: string;
  activity: HomeAlbumActivity | HomeStoryActivity;
  action: ReactNode;
}) {
  const actor = activity.actor;
  const name = actor.name;
  return (
    <>
      <PersonAvatar name={name} className="home-avatar" />
      <div className="home-byline-copy">
        {actor.person_id === null ? (
          <strong className="home-actor-link">{name}</strong>
        ) : (
          <EntityLink
            entity="person"
            className="home-actor-link"
            to={`${familyBase(familySlug)}/people/${encodeURIComponent(actor.person_id)}`}
          >
            {name}
          </EntityLink>
        )}
        <span className="home-byline-action">{action}</span>
      </div>
    </>
  );
}

function AlbumActivityCard({
  familySlug,
  activity,
}: {
  familySlug: string;
  activity: HomeAlbumActivity;
}) {
  const target = albumPath(familySlug, activity.album.id);
  const commentTarget = activity.feature_photo
    ? `${photoPath(familySlug, activity.feature_photo.id)}?albumId=${encodeURIComponent(activity.album.id)}`
    : target;
  const contributionPhotos = activity.contribution_photos.slice(0, 5);
  const singlePhoto = contributionPhotos.at(0) ?? activity.feature_photo;
  const showMosaic = activity.photo_count > 1;
  const additionalPhotoCount = Math.max(
    0,
    activity.photo_count - contributionPhotos.length,
  );
  return (
    <article className="home-feed-card">
      <div className="home-card-byline">
        <Actor
          familySlug={familySlug}
          activity={activity}
          action={
            <>
              added {activity.photo_count} new{" "}
              {activity.photo_count === 1 ? "photo" : "photos"} to{" "}
              <EntityLink
                className="home-inline-entity"
                entity="album"
                to={target}
              >
                {activity.album.name}
              </EntityLink>
            </>
          }
        />
        <FeedItemMenu entity="album" path={target} />
      </div>
      {singlePhoto === null ? (
        <div
          className="home-feature-image home-image-unavailable"
          role="status"
        >
          This photograph is currently unavailable.
        </div>
      ) : showMosaic ? (
        <EntityLink
          entity="album"
          to={target}
          className={`home-feature-image home-contribution-mosaic home-mosaic-count-${String(contributionPhotos.length)}`}
          aria-label={`Open ${activity.album.name} · ${String(activity.photo_count)} ${activity.photo_count === 1 ? "photo" : "photos"}`}
        >
          {contributionPhotos.map((photo, index) => (
            <span className="home-contribution-tile" key={photo.id}>
              <HomePhoto photo={photo} />
              {index === contributionPhotos.length - 1 &&
                additionalPhotoCount > 0 && (
                  <span
                    className="home-mosaic-more"
                    aria-label={`${String(additionalPhotoCount)} more photos`}
                  >
                    +{additionalPhotoCount}
                  </span>
                )}
            </span>
          ))}
          <span className="home-photo-count">
            {activity.photo_count}{" "}
            {activity.photo_count === 1 ? "photo" : "photos"}
          </span>
        </EntityLink>
      ) : (
        <EntityLink
          entity="album"
          to={target}
          className="home-feature-image"
          aria-label={`Open ${activity.album.name}`}
        >
          <HomePhoto photo={singlePhoto} />
          <span className="home-photo-count">
            {activity.photo_count}{" "}
            {activity.photo_count === 1 ? "photo" : "photos"}
          </span>
        </EntityLink>
      )}
      <div className="home-contribution-actions">
        <div className="home-action-row">
          <HomeLoveButton
            familySlug={familySlug}
            targetType="album"
            targetId={activity.album.id}
            engagement={activity.engagement}
          />
          <Link
            className="home-engagement"
            to={commentTarget}
            aria-label={`${String(activity.engagement.comment_count)} ${activity.engagement.comment_count === 1 ? "comment" : "comments"}`}
          >
            <ShellIcon name="message-circle" />
            <span>{activity.engagement.comment_count}</span>
          </Link>
          <EntityLink className="home-text-link" entity="album" to={target}>
            Open album →
          </EntityLink>
        </div>
      </div>
    </article>
  );
}

function StoryActivityCard({
  familySlug,
  activity,
}: {
  familySlug: string;
  activity: HomeStoryActivity;
}) {
  const target = storyPath(familySlug, activity.story.id);
  const subject = activity.story.subject;
  return (
    <article className="home-feed-card home-story-card">
      <div className="home-card-byline">
        <Actor
          familySlug={familySlug}
          activity={activity}
          action={
            <>
              added a story about{" "}
              <EntityLink
                entity={subject.type}
                className="home-inline-entity"
                to={familyEntityPath(familySlug, subject)}
              >
                {subject.label}
              </EntityLink>{" "}
              · {relativeTime(activity.created_at)}
            </>
          }
        />
        <FeedItemMenu entity="story" path={target} />
      </div>
      <p className="home-eyebrow">A family story</p>
      <h2>
        <EntityLink
          entity="story"
          to={target}
          aria-label={activity.story.heading}
        >
          {truncateWords(activity.story.heading, 15)}
        </EntityLink>
      </h2>
      <p>{activity.story.excerpt}</p>
      <div className="home-action-row">
        <HomeLoveButton
          familySlug={familySlug}
          targetType="story"
          targetId={activity.story.id}
          engagement={activity.engagement}
        />
        <EntityLink
          entity="story"
          className="home-engagement"
          to={`${target}#story-comments-title`}
          aria-label={`${String(activity.engagement.comment_count)} ${activity.engagement.comment_count === 1 ? "comment" : "comments"}`}
        >
          <ShellIcon name="message-circle" />
          <span>{activity.engagement.comment_count}</span>
        </EntityLink>
        <EntityLink className="home-text-link" entity="story" to={target}>
          Read the story →
        </EntityLink>
      </div>
    </article>
  );
}

type Birthday = { person: PersonSummary; date: Date; age: number };

function upcomingBirthdays(people: PersonSummary[]): Birthday[] {
  const now = new Date();
  const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const birthdays: Birthday[] = [];
  for (const person of people) {
    if (
      person.status === "remembered" ||
      person.birth_date.precision !== "exact" ||
      person.birth_date.value === null
    )
      continue;
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(person.birth_date.value);
    if (match === null) continue;
    const birthYear = Number(match[1]);
    const month = Number(match[2]) - 1;
    const day = Number(match[3]);
    let year = today.getFullYear();
    let date = new Date(year, month, day);
    while (
      date.getMonth() !== month ||
      date.getDate() !== day ||
      date < today
    ) {
      year += 1;
      date = new Date(year, month, day);
    }
    birthdays.push({ person, date, age: year - birthYear });
  }
  return birthdays
    .sort((a, b) => a.date.getTime() - b.date.getTime())
    .slice(0, 2);
}

function OnThisDay({
  familySlug,
  memory,
}: {
  familySlug: string;
  memory: NonNullable<ReturnType<typeof useHomeQuery>["data"]>["on_this_day"];
}) {
  if (memory === null) return null;
  const value = memory.historical_date.value;
  const year = value === null ? null : Number(value.slice(0, 4));
  const years =
    year === null || Number.isNaN(year)
      ? null
      : new Date().getFullYear() - year;
  const timing =
    memory.historical_date.precision === "exact" && years !== null
      ? `${String(years)} ${years === 1 ? "year" : "years"} ago`
      : memory.reason;
  return (
    <section
      className="home-aside-card home-memory-card"
      aria-labelledby="home-memory-title"
    >
      <ShellIcon name="sparkles" />
      <p className="home-eyebrow">On this day</p>
      <h3 id="home-memory-title">{memory.label}</h3>
      <EntityLink
        entity="photo"
        className="home-mini-photo"
        to={photoPath(familySlug, memory.photo_id)}
        aria-label={`Open ${memory.label}`}
      >
        <HomePhoto photo={memory.photo} />
      </EntityLink>
      <p>
        {timing}
        {memory.location !== null ? ` · ${memory.location}` : ""}
      </p>
      <ButtonLink
        className="home-secondary-button"
        to={photoPath(familySlug, memory.photo_id)}
      >
        See the memory
      </ButtonLink>
    </section>
  );
}

export function HomePage({ familySpace }: Props) {
  const familySlug = familySpace.slug;
  const home = useHomeQuery(familySlug);
  const user = useCurrentUserQuery();
  const canBrowsePeople = fullMemberRoles.includes(familySpace.role);
  const people = usePeopleQuery(familySlug, canBrowsePeople);
  const birthdays = useMemo(
    () => upcomingBirthdays(people.data ?? []),
    [people.data],
  );
  const canCreateAlbum = fullMemberRoles.includes(familySpace.role);
  const eyebrow = /^the\s/i.test(familySpace.name)
    ? familySpace.name
    : `The ${familySpace.name}`;
  const greeting = greetingFor(user.data?.timezone ?? "UTC");

  if (home.isPending || user.isPending)
    return (
      <p className="home-page-state" role="status">
        Opening your family Home…
      </p>
    );
  if (home.isError || user.isError)
    return (
      <p className="home-page-state" role="alert">
        Your family Home could not be loaded.
      </p>
    );

  return (
    <main className="home-page" aria-labelledby="family-space-title">
      <header className="home-page-head">
        <div>
          <p className="home-eyebrow">{eyebrow}</p>
          <h1 id="family-space-title">
            {greeting}, {user.data.name.split(/\s+/)[0] || user.data.name}
          </h1>
          <p className="home-lede">
            A few new memories have found their way home.
          </p>
        </div>
        {canCreateAlbum && (
          <ButtonLink
            variant="primary"
            className="home-primary-button"
            to={`${familyBase(familySlug)}/albums/new`}
          >
            <ShellIcon name="image-plus" />
            Create album
          </ButtonLink>
        )}
      </header>
      <div className="home-layout">
        <div className="home-feed-column">
          {home.data.activity.length === 0 ? (
            <section className="home-feed-card home-empty-feed">
              <h2>Recent family activity</h2>
              <p>
                Your family’s new Albums, Stories and photographs will appear
                here.
              </p>
            </section>
          ) : (
            home.data.activity.map((activity) =>
              activity.action_type === "photos_added_to_album" ? (
                <AlbumActivityCard
                  key={activity.id}
                  familySlug={familySlug}
                  activity={activity}
                />
              ) : (
                <StoryActivityCard
                  key={activity.id}
                  familySlug={familySlug}
                  activity={activity}
                />
              ),
            )
          )}
        </div>
        <aside className="home-aside" aria-label="Family highlights">
          <OnThisDay familySlug={familySlug} memory={home.data.on_this_day} />
          {home.data.latest_photos.length > 0 && (
            <section
              className="home-aside-card home-recently-added"
              aria-labelledby="home-latest-title"
            >
              <div className="home-aside-title">
                <div>
                  <p className="home-eyebrow">Recently added</p>
                  <h3 id="home-latest-title">Latest photographs</h3>
                </div>
                <Link to={`${familyBase(familySlug)}/photos`}>View all →</Link>
              </div>
              <div className="home-latest-grid">
                {home.data.latest_photos.map((photo, index) => (
                  <EntityLink
                    key={photo.id}
                    entity="photo"
                    to={photoPath(familySlug, photo.id)}
                    aria-label={`Open recent photograph ${String(index + 1)}`}
                  >
                    <HomePhoto photo={photo} />
                  </EntityLink>
                ))}
              </div>
            </section>
          )}
          {birthdays.length > 0 && (
            <section
              className="home-aside-card"
              aria-labelledby="home-birthdays-title"
            >
              <p className="home-eyebrow">Coming up</p>
              <h3 id="home-birthdays-title">Family birthdays</h3>
              {birthdays.map(({ person, date, age }) => (
                <div className="home-birthday" key={person.id}>
                  <EntityLink
                    entity="person"
                    className="home-birthday-avatar-link"
                    to={`${familyBase(familySlug)}/people/${encodeURIComponent(person.id)}`}
                    aria-label={`View ${person.preferred_name}`}
                  >
                    <PersonAvatar
                      name={person.preferred_name}
                      className="home-avatar"
                    />
                  </EntityLink>
                  <div>
                    <EntityLink
                      entity="person"
                      to={`${familyBase(familySlug)}/people/${encodeURIComponent(person.id)}`}
                    >
                      <strong>{person.preferred_name.split(/\s+/)[0]}</strong>
                    </EntityLink>
                    <span>
                      {new Intl.DateTimeFormat("en-GB", {
                        day: "numeric",
                        month: "long",
                      }).format(date)}{" "}
                      · turns {age}
                    </span>
                  </div>
                </div>
              ))}
            </section>
          )}
        </aside>
      </div>
    </main>
  );
}
