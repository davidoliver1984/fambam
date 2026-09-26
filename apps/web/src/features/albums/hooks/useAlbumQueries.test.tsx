import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { act, renderHook } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { familyExportKeys } from "@/features/exports/api/familyExportKeys";
import { homeKeys } from "@/features/home/hooks/useHomeQuery";
import { searchKeys } from "@/features/search/api/searchKeys";

import { deleteAlbum, updateAlbum } from "../api/albumApi";
import { albumKeys } from "../api/albumKeys";
import type { Album } from "../types/album";
import {
  useDeleteAlbumMutation,
  useUpdateAlbumMutation,
} from "./useAlbumQueries";

vi.mock("../api/albumApi", () => ({
  addPhotoToAlbum: vi.fn(),
  createAlbum: vi.fn(),
  deleteAlbum: vi.fn(),
  getAlbum: vi.fn(),
  getAlbums: vi.fn(),
  removePhotoFromAlbum: vi.fn(),
  requestAlbumExport: vi.fn(),
  setAlbumCover: vi.fn(),
  updateAlbum: vi.fn(),
  uploadPhotoToAlbum: vi.fn(),
}));

const familySlug = "family";
const albumId = "01K90000000000000000000000";
const album = {
  id: albumId,
  name: "Album",
  created_at: "2026-09-01T10:00:00+00:00",
  updated_at: "2026-09-01T10:00:00+00:00",
} as Album;

function harness() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );
  return { client, wrapper };
}

describe("Album mutations", () => {
  beforeEach(() => {
    vi.resetAllMocks();
  });

  it("invalidates Album detail/list plus affected search and Home summaries after update", async () => {
    vi.mocked(updateAlbum).mockResolvedValue({ ...album, name: "Updated" });
    const { client, wrapper } = harness();
    const keys = [
      albumKeys.list(familySlug),
      albumKeys.detail(familySlug, albumId),
      searchKeys.group(familySlug, "albums", {}),
      homeKeys.detail(familySlug),
    ];
    keys.forEach((key) => client.setQueryData(key, {}));
    const { result } = renderHook(
      () => useUpdateAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await result.current.mutateAsync({ name: "Updated" });
    });

    expect(updateAlbum).toHaveBeenCalledWith(familySlug, albumId, {
      name: "Updated",
    });
    keys.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });

  it("removes stale detail and invalidates list, search, Home and exports after delete", async () => {
    vi.mocked(deleteAlbum).mockResolvedValue();
    const { client, wrapper } = harness();
    const detailKey = albumKeys.detail(familySlug, albumId);
    const invalidated = [
      albumKeys.list(familySlug),
      searchKeys.group(familySlug, "albums", {}),
      homeKeys.detail(familySlug),
      familyExportKeys.all(familySlug),
    ];
    client.setQueryData(detailKey, album);
    invalidated.forEach((key) => client.setQueryData(key, {}));
    const { result } = renderHook(
      () => useDeleteAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await result.current.mutateAsync();
    });

    expect(client.getQueryState(detailKey)).toBeUndefined();
    invalidated.forEach((key) => {
      expect(client.getQueryState(key)?.isInvalidated).toBe(true);
    });
  });

  it("does not invalidate successful-read caches when update fails", async () => {
    vi.mocked(updateAlbum).mockRejectedValue(new Error("Update failed"));
    const { client, wrapper } = harness();
    const listKey = albumKeys.list(familySlug);
    client.setQueryData(listKey, [album]);
    const { result } = renderHook(
      () => useUpdateAlbumMutation(familySlug, albumId),
      { wrapper },
    );

    await act(async () => {
      await expect(
        result.current.mutateAsync({ name: "Unavailable" }),
      ).rejects.toThrow("Update failed");
    });

    expect(client.getQueryState(listKey)?.isInvalidated).toBe(false);
  });
});
