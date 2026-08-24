import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { createMemoryRouter, RouterProvider } from "react-router";

import { getPeople } from "@/features/people/api/personApi";
import type { Person } from "@/features/people/types/person";

import { getPhoto, submitPhotoProvenance } from "../api/photoApi";
import type { Photo, PhotoProvenanceProposal } from "../types/photo";
import { PhotoPage } from "./PhotoPage";

vi.mock("../api/photoApi", () => ({
  getPhoto: vi.fn(),
  getPhotoProvenanceProposals: vi.fn(),
  replacePhotoTags: vi.fn(),
  resolvePhotoProvenanceProposal: vi.fn(),
  submitPhotoProvenance: vi.fn(),
  updatePhoto: vi.fn(),
}));
vi.mock("@/features/people/api/personApi", () => ({ getPeople: vi.fn() }));

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
  provenance: {
    photographer: { person: null, description: "Unknown studio" },
    scanner: { person: null, description: null },
    physical_owner: { person, description: null },
  },
  tags: [{ id: "01K70000000000000000000000", label: "Picnic" }],
  created_at: "2026-08-24T10:00:00Z",
  updated_at: "2026-08-24T10:00:00Z",
  permissions: {
    can_update: false,
    can_propose_provenance: true,
    can_resolve_provenance: false,
    can_manage_tags: true,
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
  vi.mocked(getPhoto).mockResolvedValue(photo);
  vi.mocked(getPeople).mockResolvedValue([person]);
  vi.mocked(submitPhotoProvenance).mockResolvedValue(proposal);
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("PhotoPage", () => {
  it("keeps archive source and identity-bearing physical owner visibly separate", async () => {
    renderPage();
    expect(
      await screen.findByRole("heading", { name: "Family picnic" }),
    ).toBeInTheDocument();
    expect(screen.getByText("Green family album")).toBeInTheDocument();
    expect(screen.getAllByText("Aunt May")).toHaveLength(2);
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
      screen.getByLabelText("Provenance role"),
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
});
