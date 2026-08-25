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
  permissions: { can_update: boolean };
  albums?: Array<{ id: string; name: string; visibility: string }>;
  attendees?: Array<{ id: string; preferred_name: string }>;
};

export type EventInput = {
  name: string;
  description?: string | null;
  starts_on?: string | null;
  ends_on?: string | null;
  location?: string | null;
  status?: EventStatus;
};
