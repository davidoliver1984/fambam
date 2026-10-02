import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type KeyboardEvent,
} from "react";
import { useNavigate, useParams } from "react-router";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import { Button, Dialog, EntityLink } from "@/components/ui";
import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import { useRelationshipsQuery } from "@/features/people/hooks/useRelationshipQueries";
import { PhotoPresentationImage } from "@/features/photos/components/PhotoPresentationImage";
import { usePhotosQuery } from "@/features/photos/hooks/usePhotoQueries";
import { useSearchSuggestionsQuery } from "@/features/search/hooks/useArchiveSearchQuery";
import type {
  RichTextDocument,
  RichTextInlineNode,
} from "@/features/stories/types/story";

import {
  useAlbumUploadMutation,
  useCreateAlbumMutation,
  useSetAlbumCoverMutation,
} from "../hooks/useAlbumQueries";
import type { Album, CreateAlbumInput } from "../types/album";

import "./create-album.css";

type Step = 1 | 2 | 3;
type CoverChoice =
  | { kind: "none" }
  | { kind: "existing"; photoId: string }
  | { kind: "upload"; file: File };

function Icon({ name }: { name: string }) {
  const paths: Record<string, React.ReactNode> = {
    back: <path d="m15 18-6-6 6-6" />,
    next: <path d="m9 18 6-6-6-6" />,
    check: <path d="m5 12 4 4L19 6" />,
    lock: (
      <>
        <rect x="5" y="10" width="14" height="10" rx="2" />
        <path d="M8 10V7a4 4 0 0 1 8 0v3" />
      </>
    ),
    image: (
      <>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="8.5" cy="9" r="1.5" />
        <path d="m21 15-5-5L5 20" />
      </>
    ),
    plus: <path d="M12 5v14M5 12h14" />,
    pencil: (
      <>
        <path d="m4 20 4.2-1 10.6-10.6a2 2 0 0 0-2.8-2.8L5.4 16.2 4 20Z" />
        <path d="m14.5 7 2.5 2.5" />
      </>
    ),
    crop: <path d="M6 2v14a2 2 0 0 0 2 2h14M2 6h14a2 2 0 0 1 2 2v14" />,
    trash: (
      <>
        <path d="M4 7h16M9 7V4h6v3M7 7l1 13h8l1-13" />
      </>
    ),
    close: <path d="m7 7 10 10M17 7 7 17" />,
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths[name]}
    </svg>
  );
}

function richTextDescription(
  value: string,
  people: Array<{ id: string; preferred_name: string }>,
): CreateAlbumInput["description"] {
  const text = value.trim();
  if (text === "") return null;

  const candidates = [...people].sort(
    (left, right) => right.preferred_name.length - left.preferred_name.length,
  );
  const blocks: RichTextDocument["blocks"] = text
    .split(/\n{2,}/)
    .map((paragraph) => {
      const content: RichTextInlineNode[] = [];
      let cursor = 0;
      while (cursor < paragraph.length) {
        const remainder = paragraph.slice(cursor);
        const matches = candidates
          .map((person) => ({
            person,
            index: remainder
              .toLocaleLowerCase()
              .indexOf(`@${person.preferred_name.toLocaleLowerCase()}`),
          }))
          .filter((match) => match.index >= 0)
          .sort((left, right) => left.index - right.index);
        if (matches.length === 0) {
          content.push({ type: "text", text: remainder });
          break;
        }
        const match = matches[0];
        if (match.index > 0) {
          content.push({ type: "text", text: remainder.slice(0, match.index) });
        }
        content.push({
          type: "mention",
          person_id: match.person.id,
          label: match.person.preferred_name,
        });
        cursor += match.index + match.person.preferred_name.length + 1;
      }
      return { type: "paragraph" as const, content };
    });

  return { schema_version: 1, blocks };
}

function displayDate(value: string) {
  if (value === "") return "Not added";
  return new Intl.DateTimeFormat(undefined, {
    day: "numeric",
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(`${value}T00:00:00Z`));
}

function mergeTag(labels: string[], value: string) {
  const clean = value.trim().replace(/,$/, "");
  if (clean === "" || labels.length >= 25) return labels;
  if (
    labels.some(
      (label) => label.toLocaleLowerCase() === clean.toLocaleLowerCase(),
    )
  )
    return labels;
  return [...labels, clean];
}

export function CreateAlbumPage() {
  const { familySlug = "" } = useParams();
  const navigate = useNavigate();
  const family = useFamilySpaceQuery(familySlug);
  const canCreate = ["owner", "administrator", "member"].includes(
    family.data?.role ?? "",
  );
  const people = usePeopleQuery(familySlug, canCreate);
  const relationships = useRelationshipsQuery(
    familySlug,
    family.data?.current_user_person_id ?? "",
  );
  const photos = usePhotosQuery(familySlug, {}, canCreate);
  const create = useCreateAlbumMutation(familySlug);
  const upload = useAlbumUploadMutation(familySlug);
  const saveCover = useSetAlbumCoverMutation(familySlug);
  const titleRef = useRef<HTMLInputElement>(null);
  const basicsHeadingRef = useRef<HTMLHeadingElement>(null);
  const peopleHeadingRef = useRef<HTMLHeadingElement>(null);
  const reviewHeadingRef = useRef<HTMLHeadingElement>(null);
  const previousStep = useRef<Step>(1);

  const [step, setStep] = useState<Step>(1);
  const [title, setTitle] = useState("");
  const [description, setDescription] = useState("");
  const [date, setDate] = useState("");
  const [location, setLocation] = useState("");
  const [tags, setTags] = useState<string[]>([]);
  const [tagDraft, setTagDraft] = useState("");
  const [personIds, setPersonIds] = useState<string[]>([]);
  const [cover, setCover] = useState<CoverChoice>({ kind: "none" });
  const [coverPosition, setCoverPosition] = useState(0.5);
  const [coverDialog, setCoverDialog] = useState(false);
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<string, string>>
  >({});
  const [submitError, setSubmitError] = useState("");
  const [createdAlbum, setCreatedAlbum] = useState<Album | null>(null);
  const suggestions = useSearchSuggestionsQuery(
    familySlug,
    "tags",
    tagDraft.trim(),
    step === 1,
  );

  const uploadPreview = useMemo(
    () => (cover.kind === "upload" ? URL.createObjectURL(cover.file) : null),
    [cover],
  );
  useEffect(
    () => () => {
      if (uploadPreview !== null) URL.revokeObjectURL(uploadPreview);
    },
    [uploadPreview],
  );

  const relationshipLabels = useMemo(
    () =>
      new Map(
        (relationships.data ?? []).map((item) => [
          item.other_person.id,
          item.label,
        ]),
      ),
    [relationships.data],
  );
  const selectedPeople = (people.data ?? []).filter((person) =>
    personIds.includes(person.id),
  );
  const selectedPhoto =
    cover.kind === "existing"
      ? photos.data?.find((photo) => photo.id === cover.photoId)
      : undefined;
  const mentionPrefix = useMemo(() => {
    const match = /(?:^|\s)@([^@\n]*)$/.exec(description);
    return match?.[1].trim().toLocaleLowerCase() ?? null;
  }, [description]);
  const mentionSuggestions = useMemo(
    () =>
      mentionPrefix === null
        ? []
        : (people.data ?? [])
            .filter((person) =>
              person.preferred_name
                .toLocaleLowerCase()
                .startsWith(mentionPrefix),
            )
            .slice(0, 6),
    [mentionPrefix, people.data],
  );

  useEffect(() => {
    if (previousStep.current === step) return;
    previousStep.current = step;
    const heading =
      step === 1
        ? basicsHeadingRef.current
        : step === 2
          ? peopleHeadingRef.current
          : reviewHeadingRef.current;
    window.requestAnimationFrame(() => {
      heading?.focus({ preventScroll: true });
    });
  }, [step]);

  function continueFromBasics() {
    if (title.trim() === "") {
      setFieldErrors({ name: "Enter an album title." });
      titleRef.current?.focus();
      return;
    }
    setTags((current) => mergeTag(current, tagDraft));
    setTagDraft("");
    setFieldErrors({});
    setStep(2);
  }

  function goToStep(target: Step) {
    if (target > 1 && title.trim() === "") {
      setStep(1);
      setFieldErrors({ name: "Enter an album title." });
      window.requestAnimationFrame(() => titleRef.current?.focus());
      return;
    }
    setFieldErrors({});
    setStep(target);
  }

  function tagKeyDown(event: KeyboardEvent<HTMLInputElement>) {
    if (event.key === "Enter" || event.key === ",") {
      event.preventDefault();
      setTags((current) => mergeTag(current, tagDraft));
      setTagDraft("");
    }
  }

  async function finishCover(album: Album) {
    if (cover.kind === "existing") {
      await saveCover.mutateAsync({
        albumId: album.id,
        cover: {
          photoId: cover.photoId,
          confirmVisibilityWidening: true,
          focalX: 0.5,
          focalY: coverPosition,
        },
      });
    } else if (cover.kind === "upload") {
      await upload.mutateAsync({
        albumId: album.id,
        file: cover.file,
        asCover: true,
        coverFocalY: coverPosition,
      });
    }
  }

  async function createAlbum() {
    setSubmitError("");
    let albumCreatedDuringAttempt = false;
    try {
      let album = createdAlbum;
      if (album === null) {
        album = await create.mutateAsync({
          name: title.trim(),
          description: richTextDescription(description, people.data ?? []),
          visibility: "family_space",
          starts_on: date || null,
          ends_on: null,
          location: location.trim() || null,
          tags,
          person_ids: personIds,
        });
        setCreatedAlbum(album);
        albumCreatedDuringAttempt = true;
      }
      await finishCover(album);
      void navigate(
        `/families/${encodeURIComponent(familySlug)}/albums/${encodeURIComponent(album.id)}`,
      );
    } catch (error: unknown) {
      const fields = toLaravelFieldErrors(error);
      setFieldErrors(fields);
      const status = toAppError(error).status;
      setSubmitError(
        createdAlbum !== null || albumCreatedDuringAttempt
          ? "The album was created, but its cover could not be finished. Your album is safe; try the cover again."
          : status === 403
            ? "Your permission to create this album has changed."
            : (fields.album ??
              fields.name ??
              "The album could not be created. Please check the details and try again."),
      );
    }
  }

  if (family.isPending) return <p role="status">Loading album creator…</p>;
  if (family.isError)
    return <p role="alert">The album creator could not be loaded.</p>;
  if (!canCreate)
    return (
      <p role="alert">
        You do not have permission to create an album in this Family Space.
      </p>
    );

  return (
    <main
      className="create-album-wizard"
      aria-labelledby="create-album-heading"
    >
      <header className="create-album-heading">
        <h1 id="create-album-heading">Create a new album</h1>
        <p>
          {step === 3
            ? "Almost there — review the details below before creating your album."
            : "Bring the details, people and memories together in one place."}
        </p>
      </header>

      <ol
        className="create-album-stepper"
        aria-label={`Step ${String(step)} of 3`}
      >
        {["Basics", "People & access", "Review"].map((label, index) => {
          const number = (index + 1) as Step;
          return (
            <li
              key={label}
              className={
                step > number ? "complete" : step === number ? "current" : ""
              }
              aria-current={step === number ? "step" : undefined}
            >
              <button
                type="button"
                aria-label={`Go to ${label}`}
                onClick={() => {
                  goToStep(number);
                }}
              >
                <i>{step > number ? <Icon name="check" /> : number}</i>
                <b>
                  {number}. {label}
                </b>
              </button>
            </li>
          );
        })}
      </ol>

      {step === 1 && (
        <section className="create-album-card" aria-labelledby="basics-heading">
          <p className="eyebrow">The basics</p>
          <h2 ref={basicsHeadingRef} id="basics-heading" tabIndex={-1}>
            Tell us about this album
          </h2>
          <label htmlFor="album-title">Album title</label>
          <input
            ref={titleRef}
            id="album-title"
            value={title}
            maxLength={120}
            aria-invalid={fieldErrors.name !== undefined}
            aria-describedby={
              fieldErrors.name ? "album-title-error" : undefined
            }
            onChange={(event) => {
              setTitle(event.target.value);
            }}
          />
          {fieldErrors.name && (
            <p id="album-title-error" className="field-error" role="alert">
              {fieldErrors.name}
            </p>
          )}

          <label htmlFor="album-story">What’s the story?</label>
          <textarea
            id="album-story"
            value={description}
            aria-autocomplete="list"
            aria-controls={
              mentionSuggestions.length > 0
                ? "album-description-mentions"
                : undefined
            }
            onChange={(event) => {
              setDescription(event.target.value);
            }}
          />
          {mentionSuggestions.length > 0 && (
            <ul
              id="album-description-mentions"
              className="create-album-tag-suggestions"
              aria-label="Person mention suggestions"
            >
              {mentionSuggestions.map((person) => (
                <li key={person.id}>
                  <button
                    type="button"
                    onClick={() => {
                      const marker = description.lastIndexOf("@");
                      setDescription(
                        `${description.slice(0, marker)}@${person.preferred_name} `,
                      );
                    }}
                  >
                    @{person.preferred_name}
                  </button>
                </li>
              ))}
            </ul>
          )}

          <div className="create-album-field-row">
            <div>
              <label htmlFor="album-date">When was it?</label>
              <input
                id="album-date"
                type="date"
                value={date}
                onChange={(event) => {
                  setDate(event.target.value);
                }}
              />
            </div>
            <div>
              <label htmlFor="album-location">Where?</label>
              <input
                id="album-location"
                value={location}
                maxLength={255}
                onChange={(event) => {
                  setLocation(event.target.value);
                }}
              />
            </div>
          </div>

          <label htmlFor="album-tag-input">Album tags</label>
          <div className="create-album-tags-input">
            {tags.map((tag) => (
              <span key={tag.toLocaleLowerCase()}>
                {tag}
                <button
                  type="button"
                  aria-label={`Remove ${tag} tag`}
                  onClick={() => {
                    setTags((current) =>
                      current.filter((item) => item !== tag),
                    );
                  }}
                >
                  <Icon name="close" />
                </button>
              </span>
            ))}
            <input
              id="album-tag-input"
              value={tagDraft}
              maxLength={80}
              placeholder="Add tag & press enter"
              aria-autocomplete="list"
              aria-controls="album-tag-suggestions"
              onChange={(event) => {
                setTagDraft(event.target.value);
              }}
              onKeyDown={tagKeyDown}
            />
          </div>
          {suggestions.data && suggestions.data.length > 0 && (
            <ul
              id="album-tag-suggestions"
              className="create-album-tag-suggestions"
              aria-label="Tag suggestions"
            >
              {suggestions.data
                .filter(
                  (suggestion) =>
                    !tags.some(
                      (tag) =>
                        tag.toLocaleLowerCase() ===
                        suggestion.label.toLocaleLowerCase(),
                    ),
                )
                .slice(0, 6)
                .map((suggestion) => (
                  <li key={suggestion.id}>
                    <button
                      type="button"
                      onClick={() => {
                        setTags((current) =>
                          mergeTag(current, suggestion.label),
                        );
                        setTagDraft("");
                      }}
                    >
                      {suggestion.label}
                    </button>
                  </li>
                ))}
            </ul>
          )}
          {suggestions.isError && (
            <p className="field-error" role="alert">
              Existing tags could not be loaded.
            </p>
          )}
        </section>
      )}

      {step === 2 && (
        <section className="create-album-card" aria-labelledby="people-heading">
          <p className="eyebrow">People &amp; access</p>
          <h2 ref={peopleHeadingRef} id="people-heading" tabIndex={-1}>
            Who belongs in this album?
          </h2>
          <p className="create-album-lede">
            Select the people you already know are here. Face recognition can
            help with the rest after upload.
          </p>
          {people.isPending && <p role="status">Loading People…</p>}
          {people.isError && <p role="alert">People could not be loaded.</p>}
          <div className="create-album-selector-list">
            {people.data?.map((person) => (
              <label key={person.id}>
                <PersonAvatar name={person.preferred_name} />
                <span>
                  <b>{person.preferred_name}</b>
                  {relationshipLabels.has(person.id) && (
                    <small>{relationshipLabels.get(person.id)}</small>
                  )}
                </span>
                <input
                  type="checkbox"
                  aria-label={`Include ${person.preferred_name}`}
                  checked={personIds.includes(person.id)}
                  onChange={() => {
                    setPersonIds((current) =>
                      current.includes(person.id)
                        ? current.filter((id) => id !== person.id)
                        : [...current, person.id],
                    );
                  }}
                />
              </label>
            ))}
          </div>
          <div className="create-album-access-box">
            <Icon name="lock" />
            <div>
              <b>{family.data.name} only</b>
              <p>
                Everyone in the {family.data.name} can see and add to this
                album.
              </p>
            </div>
          </div>
        </section>
      )}

      {step === 3 && (
        <div className="create-album-review-sheet">
          <section className="create-album-review-section">
            <div className="create-album-review-head">
              <div>
                <p className="eyebrow">Optional</p>
                <h2 ref={reviewHeadingRef} tabIndex={-1}>
                  Cover photo
                </h2>
              </div>
              {cover.kind !== "none" && (
                <button
                  type="button"
                  className="create-album-compact-edit"
                  onClick={() => {
                    setCoverDialog(true);
                  }}
                >
                  <Icon name="pencil" />
                  Change
                </button>
              )}
            </div>
            {cover.kind === "none" ? (
              <div className="create-album-empty-cover">
                <span>
                  <Icon name="image" />
                </span>
                <div>
                  <h3>No cover photo yet</h3>
                  <p>Choose one now, or add it after creating the album.</p>
                </div>
                <button
                  type="button"
                  onClick={() => {
                    setCoverDialog(true);
                  }}
                >
                  <Icon name="plus" />
                  Add cover
                </button>
              </div>
            ) : (
              <div className="create-album-chosen-cover">
                <div
                  className="create-album-chosen-cover-image"
                  style={
                    {
                      "--cover-y": `${String(coverPosition * 100)}%`,
                    } as React.CSSProperties
                  }
                >
                  {cover.kind === "existing" && selectedPhoto ? (
                    <PhotoPresentationImage
                      familySlug={familySlug}
                      photoId={selectedPhoto.id}
                      mediaUploadId={selectedPhoto.media_upload.id}
                      fallbackTransform="display"
                      alt="Selected album cover preview"
                    />
                  ) : (
                    uploadPreview && (
                      <img
                        src={uploadPreview}
                        alt="Selected album cover preview"
                      />
                    )
                  )}
                </div>
                <div className="create-album-cover-tools">
                  <button
                    type="button"
                    onClick={() => {
                      setCoverPosition((current) =>
                        current === 0.5 ? 0.3 : 0.5,
                      );
                    }}
                  >
                    <Icon name="crop" />
                    Reposition
                  </button>
                  <button
                    type="button"
                    onClick={() => {
                      setCover({ kind: "none" });
                    }}
                  >
                    <Icon name="trash" />
                    Remove
                  </button>
                  <span>Position is saved with the album.</span>
                </div>
              </div>
            )}
          </section>

          <section className="create-album-review-section">
            <div className="create-album-review-head">
              <h2>Album details</h2>
              <button
                type="button"
                className="create-album-compact-edit"
                onClick={() => {
                  setStep(1);
                }}
              >
                <Icon name="pencil" />
                Edit
              </button>
            </div>
            <dl className="create-album-review-details">
              <div>
                <dt>Title</dt>
                <dd>{title}</dd>
              </div>
              <div>
                <dt>Description</dt>
                <dd>{description.trim() || "Not added"}</dd>
              </div>
              <div>
                <dt>Date</dt>
                <dd>{displayDate(date)}</dd>
              </div>
              <div>
                <dt>Location</dt>
                <dd>{location.trim() || "Not added"}</dd>
              </div>
              <div>
                <dt>Tags</dt>
                <dd className="create-album-review-tags">
                  {tags.length === 0
                    ? "None"
                    : tags.map((tag) => <span key={tag}>{tag}</span>)}
                </dd>
              </div>
              <div>
                <dt>Privacy</dt>
                <dd>
                  <Icon name="lock" />
                  {family.data.name} only
                </dd>
              </div>
            </dl>
          </section>

          <section className="create-album-review-section">
            <div className="create-album-review-head">
              <h2>People</h2>
              <button
                type="button"
                className="create-album-compact-edit"
                onClick={() => {
                  setStep(2);
                }}
              >
                <Icon name="pencil" />
                Edit
              </button>
            </div>
            <div className="create-album-review-people">
              {selectedPeople.map((person) => (
                <span key={person.id}>
                  <PersonAvatar name={person.preferred_name} />
                  <EntityLink
                    entity="person"
                    to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(person.id)}`}
                  >
                    <b>{person.preferred_name.split(" ")[0]}</b>
                  </EntityLink>
                </span>
              ))}
              <button
                type="button"
                onClick={() => {
                  setStep(2);
                }}
              >
                <i>
                  <Icon name="plus" />
                </i>
                <b>Add people</b>
              </button>
            </div>
          </section>
        </div>
      )}

      {submitError && (
        <p className="create-album-submit-error" role="alert">
          {submitError}
        </p>
      )}
      <div
        className={`create-album-actions${step === 3 ? " final-actions" : ""}`}
      >
        <Button
          variant="secondary"
          disabled={
            step === 1 ||
            create.isPending ||
            upload.isPending ||
            saveCover.isPending
          }
          onClick={() => {
            setStep((current) => Math.max(1, current - 1) as Step);
          }}
        >
          <Icon name="back" />
          Back
        </Button>
        <div>
          <Button
            variant="primary"
            disabled={
              create.isPending ||
              upload.isPending ||
              saveCover.isPending ||
              (step === 2 && (people.isPending || people.isError))
            }
            onClick={() => {
              if (step === 1) continueFromBasics();
              else if (step === 2) setStep(3);
              else void createAlbum();
            }}
          >
            {create.isPending
              ? "Creating…"
              : upload.isPending
                ? "Preparing cover…"
                : saveCover.isPending
                  ? "Saving cover…"
                  : createdAlbum
                    ? "Retry cover upload"
                    : step < 3
                      ? "Continue"
                      : "Create album"}
            <Icon name="next" />
          </Button>
          {step === 3 && (
            <small>
              Photos, details and the cover can all be changed later.
            </small>
          )}
        </div>
      </div>

      <Dialog
        open={coverDialog}
        title="Add cover photo"
        eyebrow="Optional"
        description="Choose a Photo already in your Family Space, upload a new cover, or leave the album without one."
        className="create-album-cover-dialog"
        onClose={() => {
          setCoverDialog(false);
        }}
      >
        <div className="create-album-cover-options">
          <button
            type="button"
            onClick={() => {
              setCover({ kind: "none" });
              setCoverDialog(false);
            }}
          >
            No cover / not now
          </button>
          <label htmlFor="new-cover-upload">
            Upload new cover
            <input
              id="new-cover-upload"
              type="file"
              accept="image/jpeg,image/png,image/heic,image/heif,image/webp,image/tiff"
              onChange={(event) => {
                const file = event.target.files?.[0];
                if (file) {
                  setCover({ kind: "upload", file });
                  setCoverPosition(0.5);
                  setCoverDialog(false);
                }
              }}
            />
          </label>
        </div>
        <h3>Choose an existing Photo</h3>
        {photos.isPending && <p role="status">Loading Photos…</p>}
        {photos.isError && (
          <p role="alert">Available Photos could not be loaded.</p>
        )}
        <div className="create-album-photo-picker">
          {photos.data?.map((photo) => (
            <button
              key={photo.id}
              type="button"
              aria-label={`Use ${photo.caption ?? photo.media_upload.client_filename} as cover`}
              onClick={() => {
                setCover({ kind: "existing", photoId: photo.id });
                setCoverPosition(0.5);
                setCoverDialog(false);
              }}
            >
              <PhotoPresentationImage
                familySlug={familySlug}
                photoId={photo.id}
                mediaUploadId={photo.media_upload.id}
                fallbackTransform="thumbnail"
                alt=""
              />
              <span>{photo.caption ?? photo.media_upload.client_filename}</span>
            </button>
          ))}
        </div>
      </Dialog>
    </main>
  );
}
