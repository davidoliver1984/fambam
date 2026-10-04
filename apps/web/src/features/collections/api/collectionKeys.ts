import type { CollectionListCriteria } from "../types/collection";

export const collectionKeys = {
  all: (familySlug: string) => ["collections", familySlug] as const,
  list: (familySlug: string, criteria?: CollectionListCriteria) =>
    criteria === undefined
      ? ([...collectionKeys.all(familySlug), "list"] as const)
      : ([...collectionKeys.all(familySlug), "list", criteria] as const),
  detail: (familySlug: string, collectionId: string) =>
    [...collectionKeys.all(familySlug), "detail", collectionId] as const,
};
