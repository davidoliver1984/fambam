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
  permissions: {
    can_update: boolean;
    can_manage_admissions: boolean;
    can_review_duplicates: boolean;
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
  valid_until: string;
};

export type EventInput = {
  name: string;
  description?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  location?: string | null;
  status?: EventStatus;
};
