export type User = {
  id: number;
  name: string;
  email: string;
  timezone: string;
  email_verified_at: string | null;
  can_invite: boolean;
};

export type UpdateProfileInput = Pick<User, "name" | "timezone">;
