export type EventStatus = "planned" | "active" | "completed" | "archived";

export type FamilyEvent = {
  id: string;
  name: string;
  description: string | null;
  starts_on: string | null;
  ends_on: string | null;
  location: string | null;
  status: EventStatus;
  created_by: number | null;
  creator: { id: number; name: string } | null;
  tags: Array<{ id: string; label: string }>;
  presentation?: {
    preview: { photo_id: string; media_upload_id: string } | null;
    photo_count: number;
    album_count: number;
    story_count: number;
    people_count: number;
  };
  permissions: {
    can_update: boolean;
    can_manage_admissions: boolean;
    can_review_duplicates: boolean;
    can_manage_exports: boolean;
    can_delete: boolean;
    can_restore: boolean;
    can_create_album: boolean;
  };
  albums?: Array<{
    id: string;
    name: string;
    visibility: string;
    guest_participation: "none" | "view" | "contribute";
  }>;
  attendees?: Array<{ id: string; preferred_name: string }>;
};

export type EventAdmission = {
  id: string;
  membership_id: string;
  user: { id: number; name: string; email: string };
  role: "owner" | "administrator" | "member" | "contributor" | "guest";
  admitted_at: string;
  revoked_at: string | null;
  rsvp_status?: EventRsvpStatus;
  rsvp_responded_at?: string | null;
  valid_until: string;
};

export type EventRsvpStatus = "pending" | "going" | "not_attending";
export type EventRsvpGroups = Record<
  EventRsvpStatus,
  Array<{ id: string; user: { id: number; name: string } }>
>;

export type EventInput = {
  name: string;
  description?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  location?: string | null;
  status?: EventStatus;
  tags?: string[];
};

export type EventExportState =
  "pending" | "processing" | "ready" | "failed" | "expired";

export type EventExport = {
  id: string;
  state: EventExportState;
  requested_by: number;
  requester: { id: number; name: string };
  photo_count: number | null;
  byte_size: number | null;
  archive_sha256: string | null;
  failure_reason: string | null;
  expires_at: string | null;
  created_at: string | null;
};

export type EventExportDownload = {
  url: string;
  expires_at: string;
};
