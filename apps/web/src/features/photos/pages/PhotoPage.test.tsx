import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getAlbum, getAlbums } from "@/features/albums/api/albumApi";
import type { Album } from "@/features/albums/types/album";
import { getCollection } from "@/features/collections/api/collectionApi";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import type { Person } from "@/features/people/types/person";
import { getPhotoVersions } from "../api/photoEditorApi";
import { getPhoto, getPhotoAlbumHistory } from "../api/photoApi";
import type { Photo } from "../types/photo";
import { PhotoPage } from "./PhotoPage";

vi.mock("@/features/albums/api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  createAlbum: vi.fn(),
  getAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
}));
vi.mock("@/features/collections/api/collectionApi", () => ({
  getCollection: vi.fn(),
}));
vi.mock("@/features/family-spaces/hooks/useFamilySpaceQuery", () => ({
  useFamilySpaceQuery: () => ({ data: { role: "owner" } }),
}));
vi.mock("@/features/events/components/EventPhotoTile", () => ({
  PhotoTileMenu: ({
    onEditDetails,
    onReviewPeople,
    showReviewPeople,
  }: {
    onEditDetails?: () => void;
    onReviewPeople?: () => void;
    showReviewPeople?: boolean;
  }) => (
    <>
      <button type="button" onClick={onEditDetails}>
        Open edit details
      </button>
      {showReviewPeople && (
        <button type="button" onClick={onReviewPeople}>
          Open people review
        </button>
      )}
    </>
  ),
}));
vi.mock("../components/PhotoConversationPanel", () => ({
  PhotoConversationPanel: ({ albumId }: { albumId?: string }) => (
    <section data-album-id={albumId}>Conversation</section>
  ),
  PhotoLoveControl: ({ albumId }: { albumId?: string }) => (
    <button type="button" data-album-id={albumId}>
      Love · 2
    </button>
  ),
}));
vi.mock("../api/photoApi", () => ({
  deletePhoto: vi.fn(),
  getPhoto: vi.fn(),
  getPhotoAlbumHistory: vi.fn(),
  getPhotoProvenanceProposals: vi.fn(),
  getPhotoMetadataProposals: vi.fn(),
  getPhotoPersonProposals: vi.fn(),
  replacePhotoTags: vi.fn(),
  resolvePhotoProvenanceProposal: vi.fn(),
  resolvePhotoMetadataProposal: vi.fn(),
  resolvePhotoPersonProposal: vi.fn(),
  submitPhotoMetadata: vi.fn(),
  submitPhotoPerson: vi.fn(),
  submitPhotoProvenance: vi.fn(),
  updatePhoto: vi.fn(),
}));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
  getOriginalMediaDelivery: vi.fn(),
}));
vi.mock("../api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoVersionDelivery: vi.fn(),
  getPhotoEditPreviewDelivery: vi.fn(),
  createPhotoEditPreview: vi.fn(),
  createRestorePreview: vi.fn(),
  applyPhotoEditPreview: vi.fn(),
  discardPhotoEditPreview: vi.fn(),
  activatePhotoVersion: vi.fn(),
}));

const person: Person = {
  id: "person-may",
  preferred_name: "Aunt May",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  birth_place: null,
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  death_place: null,
  residence_place: null,
  biography: null,
  profile_quote: null,
  profile_quote_attribution: null,
  known_for: [],
  featured_albums: [],
  relationships: [],
  account_link: null,
  redirected_from_person_id: null,
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update_authoritatively: false,
    can_propose_changes: true,
    can_resolve_proposals: false,
    can_propose_account_link: true,
    can_manage_account_link: false,
    can_propose_relationships: true,
    can_manage_relationships: false,
    can_propose_merge: true,
    can_manage_merge: false,
  },
};

const photo: Photo = {
  id: "photo-family",
  media_upload: {
    id: "upload-family",
    client_filename: "family.jpg",
    uploader: { id: 1, name: "David" },
  },
  created_by: 1,
  visibility: "family_space",
  caption: "Family picnic",
  description: "Summer together",
  archive_source_description: "Green family album",
  historical_date: { precision: "exact", value: "1986-08-14" },
  location_description: "Blackpool",
  do_not_resurface: false,
  love_count: 0,
  comment_count: 0,
  album_count: 1,
  interaction_album_id: "album-1",
  viewer_has_loved: false,
  interaction_can_interact: true,
  provenance: {
    photographer: { person: null, description: "Unknown studio" },
    scanner: { person: null, description: null },
    physical_owner: { person, description: null },
  },
  tags: [{ id: "tag-picnic", label: "Picnic" }],
  people: [
    {
      id: "association-may",
      photo_id: "photo-family",
      person,
      proposal_source: "human",
      status: "approved",
      proposed_by: 1,
      resolved_by: 1,
      resolved_at: "2026-08-24T10:00:00Z",
      created_at: "2026-08-24T10:00:00Z",
    },
  ],
  identified_faces: [
    {
      id: "face-aunt-may",
      person: { id: person.id, preferred_name: person.preferred_name },
      bounds: { x: 120, y: 80, width: 60, height: 70 },
      image_width: 600,
      image_height: 400,
    },
  ],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: true,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
    can_flag_duplicate: false,
  },
};

const album: Album = {
  id: "album-blackpool",
  name: "Blackpool, 1986",
  description: null,
  visibility: "family_space",
  created_by: 1,
  creator: { id: 1, name: "David" },
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  is_new: false,
  photo_count: 1,
  guest_participation: "none",
  photos: [
    {
      id: photo.id,
      media_upload_id: photo.media_upload.id,
      caption: photo.caption,
      client_filename: photo.media_upload.client_filename,
      visibility: "family_space",
      position: 0,
    },
  ],
  grants: [],
  permissions: { can_manage: true, can_contribute: true },
};

function renderPage(
  entry = `/families/oliver-family/photos/${photo.id}?albumId=${album.id}`,
) {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/photos/:photoId", element: <PhotoPage /> }],
    {
      initialEntries: [entry],
    },
  );
  const view = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...view, router };
}

beforeEach(() => {
  vi.mocked(getPhoto).mockResolvedValue(photo);
  vi.mocked(getPhotoAlbumHistory).mockResolvedValue([
    {
      event_type: "added",
      album: { id: album.id, name: album.name },
      actor: {
        display_name: "Aunt May",
        person_id: person.id,
        initials: "AM",
        portrait_thumbnail_url: null,
      },
      created_at: "2026-09-14T12:00:00Z",
      is_current: true,
    },
  ]);
  vi.mocked(getAlbums).mockResolvedValue({ items: [album], next_cursor: null });
  vi.mocked(getAlbum).mockResolvedValue(album);
  vi.mocked(getCollection).mockRejectedValue(new Error("Not requested"));
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: true,
    versions: [],
  });
  vi.mocked(getMediaVariantDelivery).mockResolvedValue({
    asset: "variant",
    transform_name: "display",
    processing_version: 1,
    url: "https://storage.test/signed-display",
    method: "GET",
    expires_at: "2026-08-10T12:05:00+00:00",
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoPage", () => {
  it("renders the active presentation with real Album context and metadata", async () => {
    const user = userEvent.setup();
    renderPage();
    expect(
      await screen.findByRole("img", { name: "Family picnic" }),
    ).toHaveAttribute("src", "https://storage.test/signed-display");
    expect(
      screen.getByRole("heading", { name: "Family picnic" }),
    ).toBeInTheDocument();
    const faceLink = screen.getByRole("link", { name: "View Aunt May" });
    expect(faceLink).toHaveStyle({
      left: "20%",
      top: "20%",
      width: "10%",
      height: "17.5%",
    });
    expect(faceLink).not.toHaveClass("is-visible");
    const listedPerson = document.querySelector<HTMLAnchorElement>(
      ".photo-people .ui-entity-link",
    );
    if (listedPerson === null) throw new Error("Expected a listed person link");
    await user.hover(listedPerson);
    expect(faceLink).toHaveClass("is-visible");
    await user.unhover(listedPerson);
    expect(faceLink).not.toHaveClass("is-visible");
    expect(screen.getByText("1 of 1")).toBeInTheDocument();
    expect(document.querySelector(".photo-detail-meta")).toHaveTextContent(
      /14 August 1986.*Blackpool/,
    );
    expect(screen.getAllByRole("link", { name: "Blackpool, 1986" })).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          pathname: `/families/oliver-family/albums/${album.id}`,
        }),
      ]),
    );
  });

  it("binds authorised Album-history actors, Albums and confirmed People", async () => {
    renderPage();
    expect(await screen.findByText("Album history")).toBeInTheDocument();
    expect(screen.getAllByRole("link", { name: "Aunt May" })).toEqual(
      expect.arrayContaining([
        expect.objectContaining({
          pathname: `/families/oliver-family/people/${person.id}`,
        }),
      ]),
    );
    expect(screen.getByText(/Current Album/)).toBeInTheDocument();
  });

  it("reveals People beyond the first four from the more link", async () => {
    const user = userEvent.setup();
    const namedPeople = [
      "Aunt May",
      "Jane Mercer",
      "Robert Mercer",
      "Margaret Mercer",
      "Sarah Mercer",
      "William Mercer",
    ];
    vi.mocked(getPhoto).mockResolvedValue({
      ...photo,
      people: namedPeople.map((name, index) => ({
        ...photo.people[0],
        id: `association-${String(index)}`,
        person: {
          ...person,
          id: `person-${String(index)}`,
          preferred_name: name,
        },
      })),
    });

    renderPage();
    const more = await screen.findByRole("button", { name: "2 more" });
    expect(
      screen.queryByRole("link", { name: "Sarah Mercer" }),
    ).not.toBeInTheDocument();
    await user.click(more);
    expect(
      screen.getByRole("link", { name: "Sarah Mercer" }),
    ).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "Show fewer" })).toHaveAttribute(
      "aria-expanded",
      "true",
    );
  });

  it("uses a real current Album membership for conversation, Love and cycling", async () => {
    const user = userEvent.setup();
    const cycleAlbum: Album = {
      ...album,
      photos: [
        {
          ...album.photos[0],
          id: photo.id,
          position: 0,
        },
        {
          ...album.photos[0],
          id: "photo-next",
          position: 1,
        },
        {
          ...album.photos[0],
          id: "photo-last",
          position: 2,
        },
      ],
    };
    vi.mocked(getPhotoAlbumHistory).mockResolvedValue([]);
    vi.mocked(getAlbums).mockResolvedValue({
      items: [cycleAlbum],
      next_cursor: null,
    });
    vi.mocked(getAlbum).mockResolvedValue(cycleAlbum);

    const { router } = renderPage(`/families/oliver-family/photos/${photo.id}`);

    expect(await screen.findByText("1 of 3")).toBeInTheDocument();
    expect(screen.getByText("Conversation")).toHaveAttribute(
      "data-album-id",
      album.id,
    );
    expect(screen.getByRole("button", { name: "Love · 2" })).toHaveAttribute(
      "data-album-id",
      album.id,
    );
    expect(
      screen.getByRole("link", { name: "Previous photo" }),
    ).toHaveAttribute(
      "href",
      `/families/oliver-family/photos/photo-last?albumId=${album.id}`,
    );
    expect(screen.getByRole("link", { name: "Next photo" })).toHaveAttribute(
      "href",
      `/families/oliver-family/photos/photo-next?albumId=${album.id}`,
    );

    await user.keyboard("{ArrowLeft}");
    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        "/families/oliver-family/photos/photo-last",
      );
    });

    await router.navigate(`/families/oliver-family/photos/${photo.id}`);
    await user.keyboard("{ArrowRight}");
    await waitFor(() => {
      expect(router.state.location.pathname).toBe(
        "/families/oliver-family/photos/photo-next",
      );
    });

    const comment = document.createElement("input");
    comment.setAttribute("aria-label", "Add a comment");
    document.body.append(comment);
    comment.focus();
    await user.keyboard("{ArrowLeft}");
    expect(router.state.location.pathname).toBe(
      "/families/oliver-family/photos/photo-next",
    );
    comment.remove();
  });

  it("uses persisted Collection context without forking canonical Photo Detail", async () => {
    vi.mocked(getCollection).mockResolvedValue({
      id: "collection-birthday",
      name: "William’s 50th birthday",
      description: null,
      purpose: null,
      created_at: "2026-09-26T09:00:00Z",
      updated_at: "2026-09-26T09:00:00Z",
      photo_count: 3,
      preview_photo: {
        photo_id: "photo-first",
        media_upload_id: "upload-first",
      },
      photos: [
        {
          id: "photo-first",
          caption: "First",
          media_upload_id: "upload-first",
          historical_date: null,
          location_description: null,
          people: [],
          position: 0,
        },
        {
          id: photo.id,
          caption: photo.caption,
          media_upload_id: photo.media_upload.id,
          historical_date: photo.historical_date,
          location_description: photo.location_description,
          people: [],
          position: 1,
        },
        {
          id: "photo-last",
          caption: "Last",
          media_upload_id: "upload-last",
          historical_date: null,
          location_description: null,
          people: [],
          position: 2,
        },
      ],
    });

    renderPage(
      `/families/oliver-family/photos/${photo.id}?collectionId=collection-birthday`,
    );

    expect(
      await screen.findByText(
        "You are viewing “William’s 50th birthday” collection",
      ),
    ).toBeInTheDocument();
    expect(screen.getByText("2 of 3")).toBeInTheDocument();
    expect(
      screen.getByRole("link", {
        name: "Back to “William’s 50th birthday”",
      }),
    ).toHaveAttribute(
      "href",
      "/families/oliver-family/collections/collection-birthday",
    );
    expect(
      screen.getByRole("link", {
        name: "Previous Photo in William’s 50th birthday",
      }),
    ).toHaveAttribute(
      "href",
      "/families/oliver-family/photos/photo-first?collectionId=collection-birthday",
    );
    expect(
      screen.getByRole("link", {
        name: "Next Photo in William’s 50th birthday",
      }),
    ).toHaveAttribute(
      "href",
      "/families/oliver-family/photos/photo-last?collectionId=collection-birthday",
    );
    expect(screen.getByText("Conversation")).toHaveAttribute(
      "data-album-id",
      album.id,
    );
    expect(screen.getByRole("button", { name: "Love · 2" })).toHaveAttribute(
      "data-album-id",
      album.id,
    );
  });

  it("does not show Collection context on normal Photo entry", async () => {
    renderPage();

    await screen.findByRole("heading", { name: "Family picnic" });
    expect(screen.queryByText(/You are viewing .* collection/)).toBeNull();
    expect(
      screen.queryByRole("link", { name: /Back to/ }),
    ).not.toBeInTheDocument();
    expect(getCollection).not.toHaveBeenCalled();
  });

  it("falls back to canonical Photo behavior for an unavailable or stale Collection context", async () => {
    vi.mocked(getCollection).mockRejectedValue(new Error("Unavailable"));
    renderPage(
      `/families/oliver-family/photos/${photo.id}?collectionId=deleted-collection`,
    );

    await screen.findByRole("heading", { name: "Family picnic" });
    expect(screen.queryByText(/You are viewing .* collection/)).toBeNull();
    expect(screen.getByRole("link", { name: album.name })).toHaveAttribute(
      "href",
      `/families/oliver-family/albums/${album.id}`,
    );
  });

  it("opens production edit details in the approved dialog action", async () => {
    const user = userEvent.setup();
    renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Open edit details" }),
    );
    expect(
      screen.getByRole("dialog", { name: "Edit Photo details" }),
    ).toBeInTheDocument();
    expect(screen.getByLabelText("Caption")).toHaveValue("Family picnic");
  });

  it("opens the shared photo-scoped review from Identify / Review people", async () => {
    const user = userEvent.setup();
    const { router } = renderPage();
    await user.click(
      await screen.findByRole("button", { name: "Open people review" }),
    );

    expect(router.state.location.pathname).toBe(
      "/families/oliver-family/photos/review-people",
    );
    expect(router.state.location.search).toContain("photo_id=photo-family");
    expect(router.state.location.search).toContain("return_to=");
  });
});
