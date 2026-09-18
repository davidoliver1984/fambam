export type FamilyEntity = {
  type: "person" | "photo" | "album" | "event";
  id: string;
};

export function familyEntityPath(familySlug: string, entity: FamilyEntity) {
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const segment = {
    person: "people",
    photo: "photos",
    album: "albums",
    event: "events",
  }[entity.type];

  return `${base}/${segment}/${encodeURIComponent(entity.id)}`;
}
