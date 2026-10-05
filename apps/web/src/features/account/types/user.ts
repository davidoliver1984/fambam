export type User = {
  id: number;
  name: string;
  about: string | null;
  email: string;
  pending_email: string | null;
  pending_email_requested_at: string | null;
  avatar: {
    media_upload_id: string;
    url: string;
    expires_at: string;
  } | null;
  timezone: string;
  email_verified_at: string | null;
  can_create_family_spaces: boolean;
  two_factor_enabled: boolean;
};

export type UpdateProfileInput = Pick<User, "name" | "timezone"> &
  Partial<Pick<User, "about">>;

export type RecentSignIn = {
  signed_in_at: string;
  device: string;
  location: null;
};
