import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  addStoryComment,
  createStory,
  deleteStory,
  getStory,
  removeStoryComment,
  updateStory,
} from "../api/storyApi";
import { storyKeys } from "../api/storyKeys";
import type { CreateStoryInput, RichTextDocument } from "../types/story";

export function useStoryQuery(familySlug: string, storyId: string) {
  return useQuery({
    queryKey: storyKeys.detail(familySlug, storyId),
    queryFn: ({ signal }) => getStory(familySlug, storyId, signal),
    enabled: familySlug !== "" && storyId !== "",
    retry: false,
  });
}

export function useCreateStoryMutation(familySlug: string) {
  return useMutation({
    mutationFn: (input: CreateStoryInput) => createStory(familySlug, input),
  });
}

export function useStoryMutations(familySlug: string, storyId: string) {
  const client = useQueryClient();
  const refresh = () =>
    client.invalidateQueries({
      queryKey: storyKeys.detail(familySlug, storyId),
    });
  return {
    comment: useMutation({
      mutationFn: (body: RichTextDocument) =>
        addStoryComment(familySlug, storyId, body),
      onSuccess: refresh,
    }),
    remove: useMutation({ mutationFn: () => deleteStory(familySlug, storyId) }),
    update: useMutation({
      mutationFn: (body: RichTextDocument) =>
        updateStory(familySlug, storyId, body),
      onSuccess: refresh,
    }),
    removeComment: useMutation({
      mutationFn: (commentId: string) =>
        removeStoryComment(familySlug, storyId, commentId),
      onSuccess: refresh,
    }),
  };
}
