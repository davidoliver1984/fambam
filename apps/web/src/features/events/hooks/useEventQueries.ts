import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { createAlbum } from "@/features/albums/api/albumApi";
import { albumKeys } from "@/features/albums/api/albumKeys";

import {
  createEvent,
  getDuplicateEventCandidates,
  getEvent,
  getEvents,
  getPersonEvents,
  updateEvent,
} from "../api/eventApi";
import { eventKeys } from "../api/eventKeys";
import type { EventInput } from "../types/event";

export function useEventsQuery(familySlug: string) {
  return useQuery({
    queryKey: eventKeys.list(familySlug),
    queryFn: ({ signal }) => getEvents(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}
export function useEventQuery(familySlug: string, eventId: string) {
  return useQuery({
    queryKey: eventKeys.detail(familySlug, eventId),
    queryFn: ({ signal }) => getEvent(familySlug, eventId, signal),
    enabled: familySlug !== "" && eventId !== "",
    retry: false,
  });
}
export function useDuplicateEventCandidatesQuery(
  familySlug: string,
  eventId: string,
) {
  return useQuery({
    queryKey: eventKeys.duplicates(familySlug, eventId),
    queryFn: ({ signal }) =>
      getDuplicateEventCandidates(familySlug, eventId, signal),
    enabled: familySlug !== "" && eventId !== "",
    retry: false,
  });
}
export function usePersonEventsQuery(familySlug: string, personId: string) {
  return useQuery({
    queryKey: eventKeys.person(familySlug, personId),
    queryFn: ({ signal }) => getPersonEvents(familySlug, personId, signal),
    enabled: familySlug !== "" && personId !== "",
    retry: false,
  });
}
export function useCreateEventMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: EventInput) => createEvent(familySlug, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: eventKeys.all(familySlug) }),
  });
}
export function useUpdateEventMutation(familySlug: string, eventId: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (input: Partial<EventInput>) =>
      updateEvent(familySlug, eventId, input),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: eventKeys.all(familySlug) }),
  });
}

export function useCreateEventAlbumMutation(
  familySlug: string,
  eventId: string,
) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (name: string) =>
      createAlbum(familySlug, {
        name,
        description: null,
        visibility: "family_space",
        event_id: eventId,
      }),
    onSuccess: async () => {
      await Promise.all([
        client.invalidateQueries({
          queryKey: eventKeys.detail(familySlug, eventId),
        }),
        client.invalidateQueries({ queryKey: albumKeys.all(familySlug) }),
      ]);
    },
  });
}
