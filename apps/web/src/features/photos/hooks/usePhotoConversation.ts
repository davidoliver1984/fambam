import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import {
  createPhotoText,
  getPhotoConversation,
  removePhotoReaction,
  removePhotoText,
  savePhotoReaction,
  updatePhotoText,
} from "../api/photoConversationApi";
import { photoKeys } from "../api/photoKeys";
import type { PhotoReactionType } from "../types/photoConversation";

export function usePhotoConversation(
  familySlug: string,
  photoId: string,
  albumId?: string,
) {
  return useQuery({
    queryKey: photoKeys.conversation(familySlug, photoId, albumId),
    queryFn: ({ signal }) =>
      getPhotoConversation(familySlug, photoId, albumId, signal),
    enabled: familySlug !== "" && photoId !== "",
    retry: false,
  });
}
export function usePhotoConversationMutations(
  familySlug: string,
  photoId: string,
  albumId?: string,
) {
  const client = useQueryClient();
  const refresh = () =>
    client.invalidateQueries({
      queryKey: photoKeys.conversation(familySlug, photoId, albumId),
    });
  return {
    create: useMutation({
      mutationFn: (input: { kind: "stories" | "comments"; body: string }) =>
        createPhotoText(familySlug, photoId, input.kind, input.body, albumId),
      onSuccess: refresh,
    }),
    update: useMutation({
      mutationFn: (input: {
        kind: "stories" | "comments";
        id: string;
        body: string;
      }) =>
        updatePhotoText(familySlug, photoId, input.kind, input.id, input.body),
      onSuccess: refresh,
    }),
    remove: useMutation({
      mutationFn: (input: { kind: "stories" | "comments"; id: string }) =>
        removePhotoText(familySlug, photoId, input.kind, input.id),
      onSuccess: refresh,
    }),
    react: useMutation({
      mutationFn: (reaction: PhotoReactionType) => {
        if (albumId === undefined) throw new Error("An Album is required.");
        return savePhotoReaction(familySlug, photoId, reaction, albumId);
      },
      onSuccess: refresh,
    }),
    removeReaction: useMutation({
      mutationFn: () => {
        if (albumId === undefined) throw new Error("An Album is required.");
        return removePhotoReaction(familySlug, photoId, albumId);
      },
      onSuccess: refresh,
    }),
  };
}
