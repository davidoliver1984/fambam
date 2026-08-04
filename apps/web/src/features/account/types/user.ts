export type User = {
  id: number;
  name: string;
  email: string;
  timezone: string;
  email_verified_at: string | null;
  can_create_family_spaces: boolean;
  two_factor_enabled: boolean;
};

export type UpdateProfileInput = Pick<User, "name" | "timezone">;
