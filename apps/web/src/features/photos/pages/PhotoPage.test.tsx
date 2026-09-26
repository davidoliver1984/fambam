import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getPeople } from "@/features/people/api/personApi";
import { getMediaVariantDelivery } from "@/features/media-uploads/api/mediaUploadApi";
import { getPhotoVersions } from "../api/photoEditorApi";
import type { Person } from "@/features/people/types/person";

import {
  authorizePhotoPresentationDownload,
  getPhoto,
  submitPhotoMetadata,
  submitPhotoPerson,
  submitPhotoProvenance,
} from "../api/photoApi";
import type {
  Photo,
  PhotoMetadataProposal,
  PhotoProvenanceProposal,
} from "../types/photo";
import { PhotoPage } from "./PhotoPage";

vi.mock("../api/photoApi", () => ({
  authorizePhotoPresentationDownload: vi.fn(),
  deletePhoto: vi.fn(),
  getPhoto: vi.fn(),
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
  restorePhoto: vi.fn(),
  updatePhoto: vi.fn(),
}));
vi.mock("@/features/people/api/personApi", () => ({ getPeople: vi.fn() }));
vi.mock("@/features/media-uploads/api/mediaUploadApi", () => ({
  getMediaVariantDelivery: vi.fn(),
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
  id: "01K30000000000000000000000",
  preferred_name: "Aunt May",
  alternate_names: [],
  identity_status: "confirmed",
  birth_date: { precision: "unknown", value: null },
  is_deceased: false,
  death_date: { precision: "unknown", value: null },
  biography: null,
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
  id: "01K60000000000000000000000",
  media_upload: {
    id: "01K50000000000000000000000",
    client_filename: "family.jpg",
    uploader: { id: 1, name: "David" },
  },
  created_by: 1,
  visibility: "family_space",
  caption: "Family picnic",
  description: "Summer together",
  archive_source_description: "Green family album",
  historical_date: { precision: "decade", value: "1980s" },
  location_description: "Blackpool",
  do_not_resurface: false,
  love_count: 0,
  comment_count: 0,
  album_count: 0,
  provenance: {
    photographer: { person: null, description: "Unknown studio" },
    scanner: { person: null, description: null },
    physical_owner: { person, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  people: [
    {
      id: "01K90000000000000000000000",
      photo_id: "01K60000000000000000000000",
      person,
      proposal_source: "human",
      status: "approved",
      proposed_by: 1,
      resolved_by: 1,
      resolved_at: "2026-08-24T10:00:00Z",
      created_at: "2026-08-24T10:00:00Z",
    },
  ],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: false,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
    can_flag_duplicate: false,
  },
};
const proposal: PhotoProvenanceProposal = {
  id: "01K80000000000000000000000",
  photo_id: photo.id,
  role: "scanner",
  person,
  description: null,
  clears_claim: false,
  status: "pending",
  proposed_by: 1,
  resolved_by: null,
  resolved_at: null,
  created_at: "2026-08-24T11:00:00Z",
};
const metadataProposal: PhotoMetadataProposal = {
  id: "01KA0000000000000000000000",
  photo_id: photo.id,
  field: "historical_date",
  date: { precision: "year", value: "1987" },
  location_description: null,
  clears_claim: false,
  status: "pending",
  proposed_by: 1,
  resolved_by: null,
  resolved_at: null,
  created_at: "2026-08-24T11:00:00Z",
};

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const router = createMemoryRouter(
    [{ path: "/families/:familySlug/photos/:photoId", element: <PhotoPage /> }],
    { initialEntries: [`/families/oliver-family/photos/${photo.id}`] },
  );
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(authorizePhotoPresentationDownload).mockResolvedValue({
    url: "https://storage.test/signed-presentation",
    expires_at: "2026-09-17T12:00:00Z",
    photo_version_id: null,
  });
  vi.mocked(getPhoto).mockResolvedValue(photo);
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: false,
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
  vi.mocked(getPeople).mockResolvedValue([person]);
  vi.mocked(submitPhotoProvenance).mockResolvedValue(proposal);
  vi.mocked(submitPhotoMetadata).mockResolvedValue(metadataProposal);
  vi.mocked(submitPhotoPerson).mockResolvedValue({
    ...photo.people[0],
    status: "pending",
    resolved_by: null,
    resolved_at: null,
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoPage", () => {
  it("requests Photo download authorization and shows a safe failure", async () => {
    const user = userEvent.setup();
    vi.mocked(authorizePhotoPresentationDownload).mockRejectedValueOnce(
      new Error("delivery unavailable"),
    );
    renderPage();

    await user.click(
      await screen.findByRole("button", { name: "Download Photo" }),
    );
    await waitFor(() => {
      expect(authorizePhotoPresentationDownload).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
      );
    });
    expect(
      await screen.findByText("The Photo download could not be authorised."),
    ).toHaveAttribute("role", "alert");
  });

  it("renders the display presentation variant above the Photo metadata", async () => {
    renderPage();

    expect(
      await screen.findByRole("img", { name: "Family picnic" }),
    ).toHaveAttribute("src", "https://storage.test/signed-display");
    expect(getMediaVariantDelivery).toHaveBeenCalledWith(
      "oliver-family",
      photo.media_upload.id,
      "display",
      expect.any(AbortSignal),
    );
  });

  it("keeps archive source and identity-bearing physical owner visibly separate", async () => {
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "Family picnic" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Green family album")).toBeInTheDocument();
    expect(screen.getAllByText("Aunt May").length).toBeGreaterThanOrEqual(2);
    expect(
      await screen.findByRole("link", { name: "Aunt May" }),
    ).toHaveAttribute("href", `/families/oliver-family/people/${person.id}`);
    expect(screen.getByText("decade: 1980s")).toBeInTheDocument();
    expect(screen.getByText("Blackpool")).toBeInTheDocument();
    expect(screen.getByText("Unknown studio")).toBeInTheDocument();
    expect(
      screen.queryByRole("heading", { name: "Edit Photo" }),
    ).not.toBeInTheDocument();
  });

  it("submits provenance through the feature mutation", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("heading", { name: "Photo provenance" });
    await user.selectOptions(
      await screen.findByLabelText("Provenance role"),
      "scanner",
    );
    await user.selectOptions(screen.getByLabelText("Person"), person.id);
    await user.click(screen.getByRole("button", { name: "Submit provenance" }));

    await waitFor(() => {
      expect(submitPhotoProvenance).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
        { role: "scanner", person_id: person.id },
      );
    });
    expect(screen.getByRole("status")).toHaveTextContent(
      "submitted for review",
    );
  });

  it("submits uncertain-date and Photo Person proposals through feature mutations", async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByRole("heading", { name: "Family-supplied metadata" });
    await user.selectOptions(screen.getByLabelText("Date precision"), "year");
    await user.type(screen.getByLabelText("Date value"), "1987");
    await user.click(
      screen.getByRole("button", { name: "Submit family metadata" }),
    );
    await user.selectOptions(
      screen.getByLabelText("Person appearing in this Photo"),
      person.id,
    );
    await user.click(
      screen.getByRole("button", { name: "Submit Person proposal" }),
    );

    await waitFor(() => {
      expect(submitPhotoMetadata).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
        {
          field: "historical_date",
          date: { precision: "year", value: "1987" },
        },
      );
      expect(submitPhotoPerson).toHaveBeenCalledWith(
        "oliver-family",
        photo.id,
        person.id,
      );
    });
  });
});
