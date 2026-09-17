import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, beforeEach, expect, it, vi } from "vitest";

import {
  applyPhotoEditPreview,
  createPhotoEditPreview,
  createRestorePreview,
  getPhotoEditPreviewDelivery,
  getPhotoVersions,
} from "../api/photoEditorApi";
import { identityEditRecipe } from "../types/photoEditor";
import { PhotoEditorPanel } from "./PhotoEditorPanel";

vi.mock("../api/photoEditorApi", () => ({
  getPhotoVersions: vi.fn(),
  getPhotoEditPreviewDelivery: vi.fn(),
  createPhotoEditPreview: vi.fn(),
  createRestorePreview: vi.fn(),
  applyPhotoEditPreview: vi.fn(),
  discardPhotoEditPreview: vi.fn(),
  activatePhotoVersion: vi.fn(),
}));

function renderPanel() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  return render(
    <QueryClientProvider client={client}>
      <PhotoEditorPanel familySlug="family" photoId="photo-1" />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  vi.mocked(getPhotoVersions).mockResolvedValue({
    active_photo_version_id: null,
    can_edit: true,
    versions: [],
  });
  vi.mocked(getPhotoEditPreviewDelivery).mockResolvedValue({
    asset: "photo_edit_preview",
    url: "https://storage.test/signed-preview",
    method: "GET",
    expires_at: "2026-09-17T12:30:00Z",
  });
  vi.mocked(createPhotoEditPreview).mockResolvedValue({
    outcome: "preview_ready",
    id: "preview-1",
    edit_recipe: identityEditRecipe,
    restore: null,
    expires_at: "2026-09-17T12:30:00Z",
  });
  vi.mocked(applyPhotoEditPreview).mockResolvedValue({
    id: "preview-1",
    edit_recipe: identityEditRecipe,
    restore: null,
    created_at: "2026-09-17T12:00:00Z",
  });
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

it("previews an edit before applying it", async () => {
  const user = userEvent.setup();
  renderPanel();
  await user.click(await screen.findByRole("button", { name: "Preview edit" }));
  expect(await screen.findByAltText("Proposed edit")).toHaveAttribute(
    "src",
    "https://storage.test/signed-preview",
  );
  expect(createPhotoEditPreview).toHaveBeenCalledWith(
    "family",
    "photo-1",
    identityEditRecipe,
  );
  await user.click(screen.getByRole("button", { name: "Apply version" }));
  expect(applyPhotoEditPreview).toHaveBeenCalledWith(
    "family",
    "photo-1",
    "preview-1",
  );
});

it("reports no meaningful Restore improvement without offering Apply", async () => {
  vi.mocked(createRestorePreview).mockResolvedValue({
    outcome: "no_improvement_found",
  });
  const user = userEvent.setup();
  renderPanel();
  await user.click(
    await screen.findByRole("button", { name: "Preview Restore" }),
  );
  expect(
    await screen.findByText(/Restore found no meaningful improvement/),
  ).toHaveTextContent("no meaningful improvement");
  expect(screen.queryByRole("button", { name: "Apply version" })).toBeNull();
});
