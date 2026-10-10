import {
  useEffect,
  useMemo,
  useRef,
  useState,
  type CSSProperties,
  type RefObject,
  type ReactNode,
} from "react";
import { useNavigate, useParams, useSearchParams } from "react-router";

import { apiUrl } from "@/api/client";
import { toAppError } from "@/api/errors";
import { PersonAvatar } from "@/features/family-spaces/components/PersonAvatar";
import { useCreatePersonMutation } from "@/features/people/hooks/usePersonMutations";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";
import type {
  PersonOption,
  PersonSummary,
} from "@/features/people/types/person";

import {
  useFaceReviewQuery,
  useGenerateFaceSuggestionsMutation,
  useLeaveFaceUnidentifiedMutation,
  useProposeFaceIdentityMutation,
} from "../hooks/useFaceRecognition";
import type {
  FaceReviewObservation,
  FaceReviewPhoto,
  FaceReviewSession,
} from "../types/faceRecognition";
import { faceBoundsStyle } from "../faceGeometry";

import "./face-review.css";

type Suggestions = Partial<Record<string, PersonSummary[]>>;

export function FaceReviewPage() {
  const { familySlug = "" } = useParams();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const uploadBatchId = searchParams.get("upload_batch_id") ?? undefined;
  const photoId = searchParams.get("photo_id") ?? undefined;
  const personId = searchParams.get("person_id") ?? undefined;
  const hasValidScope =
    [uploadBatchId, photoId, personId].filter((value) => value !== undefined)
      .length === 1;
  const photoScoped = photoId !== undefined && uploadBatchId === undefined;
  const returnTo = safeReturnPath(
    familySlug,
    searchParams.get("return_to"),
    photoId,
  );
  const [page, setPage] = useState(1);
  const review = useFaceReviewQuery(hasValidScope ? familySlug : "", {
    ...(uploadBatchId === undefined ? {} : { uploadBatchId }),
    ...(photoId === undefined ? {} : { photoId }),
    ...(personId === undefined ? {} : { personId }),
    limit: 100,
    page,
  });
  const people = usePeopleQuery(familySlug, hasValidScope);
  const proposeIdentity = useProposeFaceIdentityMutation(familySlug);
  const leaveUnidentified = useLeaveFaceUnidentifiedMutation(familySlug);
  const generateSuggestions = useGenerateFaceSuggestionsMutation(familySlug);
  const createPerson = useCreatePersonMutation(familySlug);
  const [currentPhotoId, setCurrentPhotoId] = useState<string | null>(null);
  const [selectedObservationId, setSelectedObservationId] = useState<
    string | null
  >(null);
  const [search, setSearch] = useState("");
  const [creating, setCreating] = useState(false);
  const [newPersonName, setNewPersonName] = useState("");
  const [suggestions, setSuggestions] = useState<Suggestions>({});
  const [skippedPhotoIds, setSkippedPhotoIds] = useState<string[]>([]);
  const [message, setMessage] = useState("");
  const [actionError, setActionError] = useState("");
  const popover = useRef<HTMLDialogElement>(null);
  const searchInput = useRef<HTMLInputElement>(null);
  const faceButtons = useRef<Record<string, HTMLButtonElement | null>>({});

  const data = review.data;
  const initialPhotoId =
    data?.photos.find(
      (photo) => photo.photo_id === data.summary.current_photo_id,
    )?.photo_id ??
    data?.photos.find((photo) => photo.remaining_count > 0)?.photo_id ??
    data?.photos[0]?.photo_id ??
    null;

  useEffect(() => {
    if (selectedObservationId !== null) searchInput.current?.focus();
  }, [selectedObservationId]);

  useEffect(() => {
    const close = (event: MouseEvent) => {
      if (
        popover.current !== null &&
        !popover.current.contains(event.target as Node)
      ) {
        setSelectedObservationId(null);
      }
    };
    document.addEventListener("mousedown", close);
    return () => {
      document.removeEventListener("mousedown", close);
    };
  }, []);

  const currentPhoto =
    data?.photos.find(
      (photo) => photo.photo_id === (currentPhotoId ?? initialPhotoId),
    ) ??
    data?.photos[0] ??
    null;
  const selectedObservation = currentPhoto?.observations.find(
    (observation) => observation.id === selectedObservationId,
  );
  const reviewablePhotos =
    data?.photos.filter(
      (photo) =>
        photo.remaining_count > 0 && !skippedPhotoIds.includes(photo.photo_id),
    ) ?? [];
  const nextPhoto = nextReviewablePhoto(currentPhoto, reviewablePhotos);
  const batchReviewComplete =
    !photoScoped &&
    data !== undefined &&
    data.summary.remaining_count === 0 &&
    !data.pagination.has_more;
  const contextualTitle =
    uploadBatchId !== undefined
      ? "Ready photographs"
      : (currentPhoto?.display_label ?? "This photograph");

  const visiblePeople = useMemo(() => {
    const allPeople = people.data ?? [];
    const suggestedIds = new Set([
      ...(selectedObservation?.suggested_people.map((person) => person.id) ??
        []),
      ...(selectedObservationId === null
        ? []
        : (suggestions[selectedObservationId] ?? []).map(
            (person) => person.id,
          )),
    ]);
    const currentPersonId =
      selectedObservation?.current_identity?.person.id ??
      selectedObservation?.current_proposal?.person.id;
    const priorityIds = [
      ...(currentPersonId === undefined ? [] : [currentPersonId]),
      ...suggestedIds,
    ];
    const priority = priorityIds
      .map((id) => allPeople.find((person) => person.id === id))
      .filter((person): person is PersonSummary => person !== undefined);
    const ordered = [
      ...priority,
      ...allPeople.filter(
        (person) => !priority.some((candidate) => candidate.id === person.id),
      ),
    ];
    return ordered
      .filter((person) =>
        person.preferred_name.toLowerCase().includes(search.toLowerCase()),
      )
      .slice(0, 5);
  }, [
    people.data,
    search,
    selectedObservation,
    selectedObservationId,
    suggestions,
  ]);

  function closePicker(returnFocus = false) {
    const observationId = selectedObservationId;
    setSelectedObservationId(null);
    setSearch("");
    setCreating(false);
    setNewPersonName("");
    if (returnFocus && observationId !== null) {
      requestAnimationFrame(() => faceButtons.current[observationId]?.focus());
    }
  }

  function selectFace(observation: FaceReviewObservation) {
    setActionError("");
    setMessage("");
    setSelectedObservationId(observation.id);
    setSearch("");
    setCreating(false);
    if (
      observation.review_state === "unreviewed" &&
      observation.suggested_people.length === 0 &&
      suggestions[observation.id] === undefined &&
      !generateSuggestions.isPending
    ) {
      generateSuggestions.mutate(observation.id, {
        onSuccess: (result) => {
          const knownPeople = people.data ?? [];
          setSuggestions((current) => ({
            ...current,
            [observation.id]: result.candidates
              .map((candidate) =>
                knownPeople.find((person) => person.id === candidate.id),
              )
              .filter(
                (person): person is PersonSummary => person !== undefined,
              ),
          }));
        },
      });
    }
  }

  async function choosePerson(person: PersonOption) {
    if (selectedObservationId === null) return;
    setActionError("");
    try {
      const assignment = await proposeIdentity.mutateAsync({
        faceObservationId: selectedObservationId,
        personId: person.id,
      });
      if (
        assignment.status !== "pending" ||
        assignment.proposal_source !== "human"
      ) {
        throw new Error("Identity selection did not create a human proposal.");
      }
      setMessage(`${person.preferred_name} proposed for identity review.`);
      closePicker(true);
    } catch (error) {
      setActionError(
        toAppError(error).message || "That identity could not be saved.",
      );
    }
  }

  async function createAndChoosePerson() {
    const name = newPersonName.trim();
    if (selectedObservationId === null || name === "") return;
    setActionError("");
    try {
      const person = await createPerson.mutateAsync({
        preferred_name: name,
        alternate_names: [],
        birth_date: { precision: "unknown", value: null },
        is_deceased: false,
        death_date: { precision: "unknown", value: null },
        biography: null,
      });
      await choosePerson(person);
    } catch (error) {
      setActionError(
        toAppError(error).message || "The Person could not be created.",
      );
    }
  }

  async function markUnidentified() {
    if (selectedObservationId === null) return;
    setActionError("");
    try {
      await leaveUnidentified.mutateAsync(selectedObservationId);
      setMessage("Face left unidentified.");
      closePicker(true);
    } catch (error) {
      setActionError(
        toAppError(error).message || "That review decision could not be saved.",
      );
    }
  }

  function goToNext(skip: boolean) {
    if (currentPhoto === null) return;
    if (skip && currentPhoto.remaining_count > 0) {
      setSkippedPhotoIds((current) => [...current, currentPhoto.photo_id]);
    }
    closePicker(false);
    if (nextPhoto !== null) {
      setCurrentPhotoId(nextPhoto.photo_id);
    } else if (data?.pagination.has_more === true) {
      setCurrentPhotoId(null);
      setPage((current) => current + 1);
    }
  }

  if (!hasValidScope) {
    return (
      <ReviewState title="Review scope unavailable">
        Open People review from Add photographs or a Photo menu. A review must
        be scoped to either one upload batch or one Photo.
      </ReviewState>
    );
  }
  if (review.isPending) {
    return (
      <ReviewState title="People to identify">Opening face review…</ReviewState>
    );
  }
  if (review.isError) {
    const notFound = toAppError(review.error).status === 404;
    return (
      <ReviewState title="People to identify" alert>
        {notFound
          ? "This photograph is unavailable or you no longer have access."
          : "Face review could not be loaded."}
      </ReviewState>
    );
  }

  const summary = review.data.summary;
  const totalProgress =
    summary.total_faces === 0
      ? 0
      : Math.round((summary.reviewed_count / summary.total_faces) * 100);

  return (
    <main className="face-review-page" aria-labelledby="face-review-title">
      <header className="face-review-top">
        <button type="button" onClick={() => void navigate(returnTo)}>
          <XIcon />
          Finish later
        </button>
        <div>
          <p className="eyebrow">People to identify</p>
          <h1 id="face-review-title">{contextualTitle}</h1>
        </div>
        <span>
          {summary.reviewed_count} of {summary.total_faces} faces reviewed
        </span>
      </header>

      <div className="face-review-workspace">
        <section className="face-review-media" aria-live="polite">
          {currentPhoto === null ? (
            <EmptyReviewState summary={summary} />
          ) : (
            <>
              <PhotoCanvas
                photo={currentPhoto}
                selectedObservationId={selectedObservationId}
                faceButtons={faceButtons}
                onSelect={selectFace}
              >
                {selectedObservation !== undefined && (
                  <dialog
                    open
                    ref={popover}
                    className={`face-review-popover${popoverOnLeft(selectedObservation, currentPhoto) ? " face-review-popover--left" : ""}`}
                    aria-label="Identify this face"
                    onKeyDown={(event) => {
                      if (event.key === "Escape") closePicker(true);
                    }}
                  >
                    <p>Who is this?</p>
                    <label className="face-review-search">
                      <SearchIcon />
                      <span className="sr-only">Search people</span>
                      <input
                        ref={searchInput}
                        type="search"
                        value={search}
                        onChange={(event) => {
                          setSearch(event.target.value);
                        }}
                        placeholder="Search people…"
                      />
                    </label>
                    <div className="face-review-person-list">
                      {visiblePeople.map((person) => (
                        <button
                          key={person.id}
                          type="button"
                          disabled={proposeIdentity.isPending}
                          onClick={() => {
                            void choosePerson(person);
                          }}
                        >
                          <PersonAvatar name={person.preferred_name} />
                          <span>
                            <b>{person.preferred_name}</b>
                            <small>
                              {selectedObservation.suggested_people.some(
                                (candidate) => candidate.id === person.id,
                              ) ||
                              suggestions[selectedObservation.id]?.some(
                                (candidate) => candidate.id === person.id,
                              ) === true
                                ? "Suggested"
                                : selectedObservation.current_proposal?.person
                                      .id === person.id
                                  ? "Current proposal"
                                  : selectedObservation.current_identity?.person
                                        .id === person.id
                                    ? "Current identity"
                                    : person.identity_status === "confirmed"
                                      ? "Person"
                                      : "Provisional Person"}
                            </small>
                          </span>
                          {selectedObservation.current_identity?.person.id ===
                            person.id && <CheckIcon />}
                        </button>
                      ))}
                      {!people.isPending && visiblePeople.length === 0 && (
                        <small className="face-review-no-people">
                          No People match that search.
                        </small>
                      )}
                    </div>
                    {selectedObservation.permissions.can_assign ||
                    selectedObservation.permissions.can_change ? (
                      <button
                        className="face-review-create"
                        type="button"
                        onClick={() => {
                          setCreating((value) => !value);
                        }}
                      >
                        <UserPlusIcon />
                        Create a new Person
                      </button>
                    ) : null}
                    {creating &&
                      (selectedObservation.permissions.can_assign ||
                        selectedObservation.permissions.can_change) && (
                        <form
                          className="face-review-new-person"
                          onSubmit={(event) => {
                            event.preventDefault();
                            void createAndChoosePerson();
                          }}
                        >
                          <input
                            aria-label="New Person name"
                            placeholder="Full name"
                            value={newPersonName}
                            onChange={(event) => {
                              setNewPersonName(event.target.value);
                            }}
                          />
                          <button
                            type="submit"
                            disabled={
                              createPerson.isPending ||
                              newPersonName.trim() === ""
                            }
                          >
                            Create
                          </button>
                        </form>
                      )}
                    {selectedObservation.permissions.can_leave_unidentified && (
                      <button
                        className="face-review-leave"
                        type="button"
                        disabled={leaveUnidentified.isPending}
                        onClick={() => {
                          void markUnidentified();
                        }}
                      >
                        Leave unidentified
                      </button>
                    )}
                  </dialog>
                )}
              </PhotoCanvas>
              <p className="face-review-caption">
                {stateGuidance(currentPhoto)}
              </p>
              {actionError !== "" && <p role="alert">{actionError}</p>}
              {message !== "" && <p role="status">{message}</p>}
              <nav className="face-review-nav" aria-label="Photograph review">
                {photoScoped ? (
                  <button
                    className="face-review-primary"
                    type="button"
                    onClick={() => void navigate(returnTo)}
                  >
                    Done <ChevronRightIcon />
                  </button>
                ) : batchReviewComplete ? (
                  <button
                    className="face-review-primary"
                    type="button"
                    onClick={() => void navigate(returnTo)}
                  >
                    Done <ChevronRightIcon />
                  </button>
                ) : (
                  <>
                    <button
                      className="face-review-secondary"
                      type="button"
                      disabled={
                        nextPhoto === null && !review.data.pagination.has_more
                      }
                      onClick={() => {
                        goToNext(true);
                      }}
                    >
                      Skip photograph
                    </button>
                    <button
                      className="face-review-primary"
                      type="button"
                      disabled={
                        nextPhoto === null && !review.data.pagination.has_more
                      }
                      onClick={() => {
                        goToNext(false);
                      }}
                    >
                      Next photograph <ChevronRightIcon />
                    </button>
                  </>
                )}
              </nav>
            </>
          )}
        </section>

        <aside className="face-review-side">
          <p className="eyebrow">This photograph</p>
          <h2>{currentPhoto?.display_label ?? "Review complete"}</h2>
          {currentPhoto !== null && (
            <p>
              {currentPhoto.detected_face_count}{" "}
              {plural("face", currentPhoto.detected_face_count)} detected ·{" "}
              {currentPhoto.remaining_count} still to identify
            </p>
          )}
          <div className="face-review-progress">
            <progress
              max={Math.max(summary.total_faces, 1)}
              value={summary.reviewed_count}
              aria-label="People review progress"
            />
            <span>{summary.reviewed_count} reviewed</span>
            <span>{summary.remaining_count} remaining</span>
            <span className="sr-only">{totalProgress}% complete</span>
          </div>
          <div className="face-review-tip">
            <SparklesIcon />
            <p>
              <b>A little context goes a long way.</b> We only show suggestions
              after you click a face, so the photograph stays calm and easy to
              explore.
            </p>
          </div>
        </aside>
      </div>
    </main>
  );
}

function PhotoCanvas({
  photo,
  selectedObservationId,
  faceButtons,
  onSelect,
  children,
}: {
  photo: FaceReviewPhoto;
  selectedObservationId: string | null;
  faceButtons: RefObject<Record<string, HTMLButtonElement | null>>;
  onSelect: (observation: FaceReviewObservation) => void;
  children: ReactNode;
}) {
  const width = photo.media.canonical_width ?? photo.media.presentation_width;
  const height =
    photo.media.canonical_height ?? photo.media.presentation_height;
  const imageUrl =
    photo.media.presentation_url ??
    apiUrl(photo.media.fallback_delivery_endpoint);
  const canPlaceFaces =
    width !== null && height !== null && width > 0 && height > 0;

  return (
    <div
      className="face-review-image"
      style={
        canPlaceFaces
          ? ({
              "--photo-ratio": `${String(width)} / ${String(height)}`,
            } as CSSProperties)
          : undefined
      }
    >
      <img src={imageUrl} alt={photo.display_label} />
      {canPlaceFaces &&
        photo.observations.map((observation) => {
          const presentedAssignment =
            observation.current_identity ?? observation.current_proposal;
          const presentedPerson = presentedAssignment?.person;
          const canChooseIdentity =
            observation.permissions.can_assign ||
            observation.permissions.can_change;
          return (
            <span
              className="face-review-identity"
              key={observation.id}
              style={faceBoundsStyle(observation.bounds, width, height)}
            >
              <button
                ref={(node) => {
                  faceButtons.current[observation.id] = node;
                }}
                type="button"
                aria-label={faceControlLabel(observation, canChooseIdentity)}
                disabled={!canChooseIdentity}
                aria-expanded={selectedObservationId === observation.id}
                aria-pressed={selectedObservationId === observation.id}
                className={`face-review-box face-review-box--${observation.review_state}${selectedObservationId === observation.id ? " is-active" : ""}${observation.reviewed ? " is-reviewed" : ""}`}
                onClick={(event) => {
                  event.stopPropagation();
                  onSelect(observation);
                }}
              />
              {presentedPerson !== undefined && (
                <span className="face-review-person-label">
                  {presentedPerson.preferred_name}
                  {observation.review_state === "human_proposal" && (
                    <small>Pending review</small>
                  )}
                </span>
              )}
              {observation.review_state === "left_unidentified" && (
                <span className="face-review-person-label face-review-person-label--unidentified">
                  Unidentified
                </span>
              )}
            </span>
          );
        })}
      {children}
    </div>
  );
}

function EmptyReviewState({
  summary,
}: {
  summary: FaceReviewSession["summary"];
}) {
  let title = "No faces to review";
  let copy = "No faces were detected in these photographs.";
  if (summary.analysis.pending > 0 || summary.analysis.processing > 0) {
    title = "Face analysis is still processing";
    copy = "You can finish for now and return when the photographs are ready.";
  } else if (summary.analysis.failed > 0 && summary.total_faces === 0) {
    title = "Face analysis is unavailable";
    copy = "No reviewable faces are available from these photographs.";
  } else if (summary.total_faces > 0 && summary.remaining_count === 0) {
    title = "All faces reviewed";
    copy = "Every detected face in this review has a recorded decision.";
  }
  return (
    <div className="face-review-empty">
      <h2>{title}</h2>
      <p>{copy}</p>
    </div>
  );
}

function ReviewState({
  title,
  alert = false,
  children,
}: {
  title: string;
  alert?: boolean;
  children: ReactNode;
}) {
  return (
    <main className="face-review-page face-review-state">
      <p className="eyebrow">People to identify</p>
      <h1>{title}</h1>
      <p role={alert ? "alert" : "status"}>{children}</p>
    </main>
  );
}

function safeReturnPath(
  familySlug: string,
  requested: string | null,
  photoId?: string,
) {
  const familyPath = `/families/${encodeURIComponent(familySlug)}`;
  if (requested?.startsWith(`${familyPath}/`) === true) return requested;
  return photoId === undefined
    ? `${familyPath}/photos`
    : `${familyPath}/photos/${encodeURIComponent(photoId)}`;
}

function nextReviewablePhoto(
  current: FaceReviewPhoto | null,
  photos: FaceReviewPhoto[],
) {
  if (photos.length === 0) return null;
  const index = photos.findIndex(
    (photo) => photo.photo_id === current?.photo_id,
  );
  if (index < 0) return photos[0];
  return (
    photos.at(index + 1) ??
    photos.find((photo) => photo.photo_id !== current?.photo_id) ??
    null
  );
}

function faceControlLabel(
  observation: FaceReviewObservation,
  canChooseIdentity: boolean,
) {
  const face = String(observation.face_index + 1);
  if (observation.review_state === "approved_identity") {
    const name = observation.current_identity?.person.preferred_name;
    return canChooseIdentity
      ? `Change identity for face ${face}, currently ${name ?? "identified"}`
      : `Face ${face} identified as ${name ?? "a Person"}`;
  }
  if (observation.review_state === "human_proposal") {
    const name = observation.current_proposal?.person.preferred_name;
    return canChooseIdentity
      ? `Change proposed identity for face ${face}, currently ${name ?? "pending review"}`
      : `Face ${face} has a pending identity proposal for ${name ?? "a Person"}`;
  }
  if (observation.review_state === "automatic_suggestion") {
    return canChooseIdentity
      ? `Review suggested identity for face ${face}`
      : `Face ${face} has an identity suggestion`;
  }
  if (observation.review_state === "left_unidentified") {
    return canChooseIdentity
      ? `Identify face ${face}, currently left unidentified`
      : `Face ${face} left unidentified`;
  }
  return `Identify face ${face}`;
}

function popoverOnLeft(
  observation: FaceReviewObservation,
  photo: FaceReviewPhoto,
) {
  const width = photo.media.canonical_width ?? photo.media.presentation_width;
  return width !== null && observation.bounds.x > width / 2;
}

function stateGuidance(photo: FaceReviewPhoto) {
  const copy: Record<FaceReviewPhoto["analysis"]["review_state"], string> = {
    pending: "Face analysis has not started yet.",
    processing: "Face analysis is still processing.",
    failed: "Face analysis is unavailable for this photograph.",
    succeeded_with_zero_faces: "No faces were detected in this photograph.",
    succeeded_with_all_faces_resolved:
      "All faces in this photograph have been reviewed.",
    succeeded_with_unresolved_faces:
      "Click a face to identify them. A name stays with the face once selected.",
  };
  return copy[photo.analysis.review_state];
}

function plural(word: string, count: number) {
  return count === 1 ? word : `${word}s`;
}

function XIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="M6 6l12 12M18 6 6 18" />
    </svg>
  );
}
function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="11" cy="11" r="7" />
      <path d="m16 16 5 5" />
    </svg>
  );
}
function CheckIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="m5 12 4 4L19 6" />
    </svg>
  );
}
function UserPlusIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <circle cx="9" cy="8" r="4" />
      <path d="M2 21c0-4 3-7 7-7s7 3 7 7M19 8v6M16 11h6" />
    </svg>
  );
}
function ChevronRightIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="m9 5 7 7-7 7" />
    </svg>
  );
}
function SparklesIcon() {
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <path d="m12 2 1.5 5.5L19 9l-5.5 1.5L12 16l-1.5-5.5L5 9l5.5-1.5L12 2ZM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8L19 15Z" />
    </svg>
  );
}
