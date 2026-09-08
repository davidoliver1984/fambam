import "@testing-library/jest-dom/vitest";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { cleanup, render, screen, waitFor } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { afterEach, describe, expect, it, vi } from "vitest";

import {
  createSavedSearch,
  deleteSavedSearch,
  getSavedSearches,
  updateSavedSearch,
} from "../api/searchApi";
import { SavedSearchPanel } from "./SavedSearchPanel";

vi.mock("../api/searchApi", () => ({
  getSavedSearches: vi.fn(),
  createSavedSearch: vi.fn(),
  updateSavedSearch: vi.fn(),
  deleteSavedSearch: vi.fn(),
  runSavedSearch: vi.fn(),
}));

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe("SavedSearchPanel", () => {
  it("exposes functional create, run, replace and delete controls", async () => {
    const item = {
      id: "saved-1",
      name: "Family search",
      filters: { schema_version: 1 as const, q: "family" },
      people: [],
    };
    vi.mocked(getSavedSearches).mockResolvedValue([item]);
    vi.mocked(createSavedSearch).mockResolvedValue(item);
    vi.mocked(updateSavedSearch).mockResolvedValue(item);
    vi.mocked(deleteSavedSearch).mockResolvedValue();
    const onRun = vi.fn();
    const client = new QueryClient({
      defaultOptions: { queries: { retry: false } },
    });
    render(
      <QueryClientProvider client={client}>
        <SavedSearchPanel
          familySlug="family-archive"
          criteria={{ q: "summer" }}
          onRun={onRun}
        />
      </QueryClientProvider>,
    );

    const user = userEvent.setup();
    await user.type(screen.getByLabelText("Name this search"), "Summer");
    await user.click(
      screen.getByRole("button", { name: "Save current search" }),
    );
    expect(createSavedSearch).toHaveBeenCalledWith("family-archive", {
      name: "Summer",
      filters: { q: "summer" },
    });
    await user.click(await screen.findByRole("button", { name: "Run" }));
    expect(onRun).toHaveBeenCalledWith("saved-1");
    await user.click(
      screen.getByRole("button", { name: "Replace with current search" }),
    );
    expect(updateSavedSearch).toHaveBeenCalledWith(
      "family-archive",
      "saved-1",
      { name: "Family search", filters: { q: "summer" } },
    );
    await user.click(screen.getByRole("button", { name: "Delete" }));
    await waitFor(() => {
      expect(deleteSavedSearch).toHaveBeenCalledWith(
        "family-archive",
        "saved-1",
      );
    });
  });
});
