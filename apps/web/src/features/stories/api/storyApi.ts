import { apiClient, ensureCsrfCookie } from "@/api/client";
import { type ApiEnvelope, unwrap } from "@/api/envelope";

import type {
  CreateStoryInput,
  RichTextDocument,
  Story,
  StoryComment,
} from "../types/story";

function storyPath(familySlug: string, storyId?: string): string {
  const base = `/api/families/${encodeURIComponent(familySlug)}/stories`;
  return storyId === undefined
    ? base
    : `${base}/${encodeURIComponent(storyId)}`;
}

export async function getStory(
  familySlug: string,
  storyId: string,
  signal?: AbortSignal,
): Promise<Story> {
  return unwrap(
    await apiClient.get<ApiEnvelope<Story>>(storyPath(familySlug, storyId), {
      signal,
    }),
  );
}

export async function createStory(
  familySlug: string,
  input: CreateStoryInput,
): Promise<Story> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<Story>>(storyPath(familySlug), input),
  );
}

export async function addStoryComment(
  familySlug: string,
  storyId: string,
  body: RichTextDocument,
): Promise<StoryComment> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.post<ApiEnvelope<StoryComment>>(
      `${storyPath(familySlug, storyId)}/comments`,
      { body },
    ),
  );
}

export async function updateStory(
  familySlug: string,
  storyId: string,
  body: RichTextDocument,
): Promise<Story> {
  await ensureCsrfCookie();
  return unwrap(
    await apiClient.patch<ApiEnvelope<Story>>(storyPath(familySlug, storyId), {
      body,
    }),
  );
}

export async function removeStoryComment(
  familySlug: string,
  storyId: string,
  commentId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(
    `${storyPath(familySlug, storyId)}/comments/${encodeURIComponent(commentId)}`,
  );
}

export async function deleteStory(
  familySlug: string,
  storyId: string,
): Promise<void> {
  await ensureCsrfCookie();
  await apiClient.delete(storyPath(familySlug, storyId));
}
