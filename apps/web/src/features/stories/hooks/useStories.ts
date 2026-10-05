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
import { homeKeys } from "@/features/home/hooks/useHomeQuery";
import type { CreateStoryInput, RichTextDocument, Story } from "../types/story";

export function useStoryQuery(familySlug: string, storyId: string) {
  return useQuery({
    queryKey: storyKeys.detail(familySlug, storyId),
    queryFn: ({ signal }) => getStory(familySlug, storyId, signal),
    enabled: familySlug !== "" && storyId !== "",
    retry: false,
  });
}

export function useCreateStoryMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: CreateStoryInput) => createStory(familySlug, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
  });
}

export function useStoryMutations(familySlug: string, storyId: string) {
  const client = useQueryClient();
  const refresh = () =>
    Promise.all([
      client.invalidateQueries({
        queryKey: storyKeys.detail(familySlug, storyId),
      }),
      client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
    ]);
  return {
    comment: useMutation({
      mutationFn: (body: RichTextDocument) =>
        addStoryComment(familySlug, storyId, body),
      onSuccess: refresh,
    }),
    remove: useMutation({
      mutationFn: () => deleteStory(familySlug, storyId),
      onSuccess: () =>
        client.invalidateQueries({ queryKey: homeKeys.detail(familySlug) }),
    }),
    update: useMutation({
      mutationFn: (body: RichTextDocument) =>
        updateStory(familySlug, storyId, body),
      onSuccess: (updatedStory) => {
        client.setQueryData<Story>(
          storyKeys.detail(familySlug, storyId),
          (current) =>
            current === undefined
              ? updatedStory
              : { ...current, ...updatedStory },
        );
        return refresh();
      },
    }),
    removeComment: useMutation({
      mutationFn: (commentId: string) =>
        removeStoryComment(familySlug, storyId, commentId),
      onSuccess: refresh,
    }),
  };
}
