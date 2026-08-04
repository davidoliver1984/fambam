export const familySpaceKeys = {
  all: ["family-spaces"] as const,
  list: () => [...familySpaceKeys.all, "list"] as const,
};
