import { useMutation, useQuery, useQueryClient } from "@tanstack/react-query";

import { createAlbum } from "@/features/albums/api/albumApi";
import { albumKeys } from "@/features/albums/api/albumKeys";

import {
  createEvent,
  admitEventMembership,
  deleteEvent,
  getDeletedEvents,
  getDuplicateEventCandidates,
  getEventAdmissions,
  getEvent,
  getEvents,
  getPersonEvents,
  updateEvent,
  revokeEventAdmission,
  restoreEvent,
} from "../api/eventApi";
import { eventKeys } from "../api/eventKeys";
import type { EventInput } from "../types/event";
import type { GuestParticipation } from "@/features/albums/types/album";

export function useEventsQuery(familySlug: string) {
  return useQuery({
    queryKey: eventKeys.list(familySlug),
    queryFn: ({ signal }) => getEvents(familySlug, signal),
    enabled: familySlug !== "",
    retry: false,
  });
}
export function useDeletedEventsQuery(familySlug: string, enabled: boolean) {
  return useQuery({
    queryKey: eventKeys.deleted(familySlug),
    queryFn: ({ signal }) => getDeletedEvents(familySlug, signal),
    enabled: enabled && familySlug !== "",
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
  enabled = true,
) {
  return useQuery({
    queryKey: eventKeys.duplicates(familySlug, eventId),
    queryFn: ({ signal }) =>
      getDuplicateEventCandidates(familySlug, eventId, signal),
    enabled: enabled && familySlug !== "" && eventId !== "",
    retry: false,
  });
}

export function useEventAdmissionsQuery(
  familySlug: string,
  eventId: string,
  enabled: boolean,
) {
  return useQuery({
    queryKey: eventKeys.admissions(familySlug, eventId),
    queryFn: ({ signal }) => getEventAdmissions(familySlug, eventId, signal),
    enabled: enabled && familySlug !== "" && eventId !== "",
    retry: false,
  });
}

export function useEventAdmissionMutations(
  familySlug: string,
  eventId: string,
) {
  const client = useQueryClient();
  const invalidate = () =>
    client.invalidateQueries({
      queryKey: eventKeys.admissions(familySlug, eventId),
    });
  return {
    admit: useMutation({
      mutationFn: (membershipId: string) =>
        admitEventMembership(familySlug, eventId, membershipId),
      onSuccess: invalidate,
    }),
    revoke: useMutation({
      mutationFn: (membershipId: string) =>
        revokeEventAdmission(familySlug, eventId, membershipId),
      onSuccess: invalidate,
    }),
  };
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

export function useDeleteEventMutation(familySlug: string, eventId: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: () => deleteEvent(familySlug, eventId),
    onSuccess: () =>
      client.invalidateQueries({ queryKey: eventKeys.all(familySlug) }),
  });
}

export function useRestoreEventMutation(familySlug: string) {
  const client = useQueryClient();
  return useMutation({
    mutationFn: (eventId: string) => restoreEvent(familySlug, eventId),
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
    mutationFn: (input: {
      name: string;
      guestParticipation: GuestParticipation;
    }) =>
      createAlbum(familySlug, {
        name: input.name,
        description: null,
        visibility: "family_space",
        event_id: eventId,
        guest_participation: input.guestParticipation,
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
