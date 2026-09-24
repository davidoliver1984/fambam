export function initials(name: string) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

const blueAvatarNames = new Set([
  "david",
  "james",
  "paul",
  "robert",
  "thomas",
  "william",
]);

export function avatarTone(name: string) {
  const firstName = name.trim().split(/\s+/)[0]?.toLowerCase() ?? "";
  return blueAvatarNames.has(firstName) ? " avatar--blue" : "";
}
