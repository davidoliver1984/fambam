import type { AlbumListCriteria } from "../types/album";

export const albumKeys = {
  all: (familySlug: string) => ["albums", familySlug] as const,
  list: (familySlug: string) => [...albumKeys.all(familySlug), "list"] as const,
  page: (familySlug: string, criteria: AlbumListCriteria) =>
    [...albumKeys.list(familySlug), criteria] as const,
  detail: (familySlug: string, albumId: string) =>
    [...albumKeys.all(familySlug), "detail", albumId] as const,
};
