import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { renderHook } from "@testing-library/react";
import type { PropsWithChildren } from "react";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { familyExportKeys } from "@/features/exports/api/familyExportKeys";

import {
  addCollectionPhotos,
  reorderCollectionPhotos,
  requestCollectionExport,
  updateCollection,
} from "../api/collectionApi";
import { collectionKeys } from "../api/collectionKeys";
import {
  useAddCollectionPhotosMutation,
  useCollectionMutations,
  useReorderCollectionPhotosMutation,
  useUpdateCollectionMutation,
} from "./useCollections";

vi.mock("../api/collectionApi", () => ({
  addCollectionPhoto: vi.fn(),
  addCollectionPhotos: vi.fn(),
  createCollection: vi.fn(),
  deleteCollection: vi.fn(),
  getCollection: vi.fn(),
  getCollections: vi.fn(),
  populateCollection: vi.fn(),
  removeCollectionPhoto: vi.fn(),
  reorderCollectionPhotos: vi.fn(),
  requestCollectionExport: vi.fn(),
  updateCollection: vi.fn(),
}));

const familySlug = "family";
const collectionId = "collection";
const collection = {
  id: collectionId,
  name: "Favourites",
  description: null,
  created_at: null,
  photos: [],
};
const familyExport = {
  id: "export",
  scope: "collection" as const,
  collection_id: collectionId,
  state: "pending" as const,
  photo_count: null,
  byte_size: null,
  failure_reason: null,
  expires_at: null,
  created_at: null,
};

describe("Collection mutations", () => {
  beforeEach(() => {
    vi.mocked(updateCollection).mockResolvedValue(collection);
    vi.mocked(reorderCollectionPhotos).mockResolvedValue(collection);
    vi.mocked(addCollectionPhotos).mockResolvedValue(collection);
    vi.mocked(requestCollectionExport).mockResolvedValue(familyExport);
  });

  it("invalidates the list and detail after update", async () => {
    const { client, wrapper } = setup();
    const detail = collectionKeys.detail(familySlug, collectionId);
    const list = collectionKeys.list(familySlug);
    client.setQueryData(detail, collection);
    client.setQueryData(list, [collection]);
    const { result } = renderHook(
      () => useUpdateCollectionMutation(familySlug, collectionId),
      { wrapper },
    );

    await result.current.mutateAsync({ name: "Renamed" });

    expect(updateCollection).toHaveBeenCalledWith(familySlug, collectionId, {
      name: "Renamed",
    });
    expect(client.getQueryState(detail)?.isInvalidated).toBe(true);
    expect(client.getQueryState(list)?.isInvalidated).toBe(true);
  });

  it("invalidates detail after reorder and batch add", async () => {
    const { client, wrapper } = setup();
    const detail = collectionKeys.detail(familySlug, collectionId);
    client.setQueryData(detail, collection);
    const reorder = renderHook(
      () => useReorderCollectionPhotosMutation(familySlug, collectionId),
      { wrapper },
    );
    const batch = renderHook(
      () => useAddCollectionPhotosMutation(familySlug, collectionId),
      { wrapper },
    );

    await reorder.result.current.mutateAsync(["second", "first"]);
    expect(reorderCollectionPhotos).toHaveBeenCalledWith(
      familySlug,
      collectionId,
      ["second", "first"],
    );
    expect(client.getQueryState(detail)?.isInvalidated).toBe(true);
    client.setQueryData(detail, collection);

    await batch.result.current.mutateAsync(["first", "second"]);
    expect(addCollectionPhotos).toHaveBeenCalledWith(familySlug, collectionId, [
      "first",
      "second",
    ]);
    expect(client.getQueryState(detail)?.isInvalidated).toBe(true);
  });

  it("returns the export and invalidates the canonical export query", async () => {
    const { client, wrapper } = setup();
    const exportsKey = familyExportKeys.all(familySlug);
    client.setQueryData(exportsKey, []);
    const { result } = renderHook(
      () => useCollectionMutations(familySlug, collectionId),
      { wrapper },
    );

    await expect(result.current.requestExport.mutateAsync()).resolves.toEqual(
      familyExport,
    );
    expect(client.getQueryState(exportsKey)?.isInvalidated).toBe(true);
  });
});

function setup() {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  });
  const wrapper = ({ children }: PropsWithChildren) => (
    <QueryClientProvider client={client}>{children}</QueryClientProvider>
  );

  return { client, wrapper };
}
