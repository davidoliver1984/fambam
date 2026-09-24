export function splitEventTags(value: string): string[] {
  const labels = new Map<string, string>();
  for (const candidate of value.split(",")) {
    const display = candidate.trim().replace(/\s+/g, " ");
    const key = display.toLocaleLowerCase();
    if (display !== "" && !labels.has(key)) labels.set(key, display);
  }
  return [...labels.values()];
}
