import {
  useEffect,
  useRef,
  useState,
  type ReactNode,
  type SyntheticEvent,
} from "react";
import {
  Link,
  Navigate,
  useNavigate,
  useOutletContext,
  useParams,
} from "react-router";

import { toAppError, toLaravelFieldErrors } from "@/api/errors";
import {
  Breadcrumbs,
  ActionNotice,
  type ActionNoticeMessage,
  Button,
  ButtonLink,
  ConfirmDialog,
  ContextMenu,
  Dialog,
  PageHeader,
} from "@/components/ui";
import {
  PasswordChangeForm,
  TwoFactorPanel,
} from "@/features/account/components/AccountSecurityPanel";
import {
  useAccountAvatarMutations,
  useRecentSignInsQuery,
  useRequestEmailChangeMutation,
} from "@/features/account/hooks/useAccountSettings";
import type { AppearancePreference } from "@/features/account/hooks/useAppearancePreference";
import { useCurrentUserQuery } from "@/features/account/hooks/useCurrentUserQuery";
import { useUpdateProfileMutation } from "@/features/account/hooks/useUpdateProfileMutation";
import {
  useIssueInvitationMutation,
  useTransitionInvitationMutation,
} from "@/features/invitations/hooks/useInvitationMutations";
import { useInvitationsQuery } from "@/features/invitations/hooks/useInvitationsQuery";
import type { Invitation } from "@/features/invitations/types/invitation";
import {
  useNotificationPresentationPreferencesQuery,
  useUpdateNotificationPresentationPreferences,
} from "@/features/notifications/hooks/useNotifications";
import type { NotificationPresentationPreference } from "@/features/notifications/types/notification";
import { usePersonQuery } from "@/features/people/hooks/usePersonQuery";

import { PersonAvatar } from "../components/PersonAvatar";
import { ShellIcon } from "../components/ShellIcon";
import { useFamilySpaceQuery } from "../hooks/useFamilySpaceQuery";
import {
  useFamilySpaceMembershipMutations,
  useFamilySpaceMembershipsQuery,
} from "../hooks/useFamilyMemberships";
import { useFamilySettingsMutations } from "../hooks/useFamilySettingsMutations";
import { useSettingsOverviewQuery } from "../hooks/useSettingsOverviewQuery";
import type {
  FamilyMembership,
  FamilySpace,
  FamilySpaceRole,
} from "../types/familySpace";
import type { ArchiveHealthMetric } from "../types/settingsOverview";

import "./SettingsPage.css";

type SettingsSection =
  "profile" | "appearance" | "security" | "family" | "overview";

type ShellContext = {
  appearancePreference: AppearancePreference;
  setAppearancePreference: (preference: AppearancePreference) => void;
};

const sections: Array<{ key: SettingsSection; label: string }> = [
  { key: "profile", label: "Profile" },
  { key: "appearance", label: "Appearance" },
  { key: "security", label: "Account & security" },
  { key: "family", label: "Family settings" },
  { key: "overview", label: "Family overview" },
];

function isSettingsSection(
  value: string | undefined,
): value is SettingsSection {
  return sections.some((section) => section.key === value);
}

export function SettingsPage() {
  const { familySlug = "", section } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const shell = useOutletContext<ShellContext>();
  const base = `/families/${encodeURIComponent(familySlug)}`;
  const [notice, setNotice] = useState<ActionNoticeMessage | null>(null);

  useEffect(() => {
    if (notice === null) return;
    const timeout = window.setTimeout(() => {
      setNotice(null);
    }, 5000);
    return () => {
      window.clearTimeout(timeout);
    };
  }, [notice]);

  if (section === undefined)
    return <Navigate to={`${base}/settings/profile`} replace />;
  if (!isSettingsSection(section))
    return <Navigate to={`${base}/settings/profile`} replace />;
  if (family.isPending)
    return (
      <p className="settings-state" role="status">
        Loading Settings…
      </p>
    );
  if (family.isError)
    return (
      <p className="settings-state" role="alert">
        Settings could not be loaded.
      </p>
    );

  return (
    <main className="settings-page" aria-labelledby="settings-title">
      <Breadcrumbs
        items={[{ label: "Home", to: base }, { label: "Settings" }]}
      />
      <PageHeader
        id="settings-title"
        eyebrow="Your account"
        title="Settings"
        description={`Manage your profile, account security and ${family.data.name} family settings.`}
      />
      <nav className="settings-tabs" aria-label="Settings sections">
        <span>On this page</span>
        {sections.map((item) => (
          <Link
            key={item.key}
            to={`${base}/settings/${item.key}`}
            className={section === item.key ? "active" : undefined}
            aria-current={section === item.key ? "page" : undefined}
          >
            {item.label}
          </Link>
        ))}
      </nav>
      {section === "profile" && (
        <ProfileSettings family={family.data} onNotice={setNotice} />
      )}
      {section === "appearance" && (
        <AppearanceSettings
          preference={shell.appearancePreference}
          setPreference={shell.setAppearancePreference}
          onNotice={setNotice}
        />
      )}
      {section === "security" && <SecuritySettings onNotice={setNotice} />}
      {section === "family" && (
        <FamilySettings family={family.data} onNotice={setNotice} />
      )}
      {section === "overview" && (
        <FamilyOverview family={family.data} onNotice={setNotice} />
      )}
      {notice && (
        <ActionNotice
          {...notice}
          onDismiss={() => {
            setNotice(null);
          }}
        />
      )}
    </main>
  );
}

function ProfileSettings({
  family,
  onNotice,
}: {
  family: FamilySpace;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const user = useCurrentUserQuery();
  const profile = useUpdateProfileMutation();
  const email = useRequestEmailChangeMutation();
  const avatar = useAccountAvatarMutations(family.slug);
  const file = useRef<HTMLInputElement>(null);
  const [name, setName] = useState("");
  const [emailAddress, setEmailAddress] = useState("");
  const [about, setAbout] = useState("");
  const [currentPassword, setCurrentPassword] = useState("");
  const [message, setMessage] = useState("");
  const [fieldErrors, setFieldErrors] = useState<
    Partial<Record<string, string>>
  >({});

  useEffect(() => {
    if (!user.data) return;
    // The server response is the source of truth when this form first opens or refreshes.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setName(user.data.name);
    setEmailAddress(user.data.email);
    setAbout(user.data.about ?? "");
  }, [user.data]);

  if (user.isPending) return <p role="status">Loading your profile…</p>;
  if (user.isError)
    return <p role="alert">Your profile could not be loaded.</p>;

  const currentUser = user.data;
  const emailChanged = emailAddress.trim() !== currentUser.email;
  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    setFieldErrors({});
    try {
      await profile.mutateAsync({
        name: name.trim(),
        about: about.trim() || null,
        timezone: currentUser.timezone,
      });
      if (emailChanged) {
        await email.mutateAsync({
          email: emailAddress.trim(),
          current_password: currentPassword,
        });
      }
      setCurrentPassword("");
      onNotice({
        title: "Profile saved",
        description: emailChanged
          ? "Check your new address to confirm the email change."
          : undefined,
      });
    } catch (error) {
      setFieldErrors(toLaravelFieldErrors(error));
      setMessage("Your changes could not be saved.");
    }
  }

  return (
    <div className="settings-grid">
      <section className="settings-card" aria-labelledby="profile-card-title">
        <h2 id="profile-card-title">Your profile</h2>
        <form className="profile-form" onSubmit={(event) => void submit(event)}>
          <div className="settings-photo-wrap">
            <span className="settings-photo" aria-hidden="true">
              {user.data.avatar ? (
                <img src={user.data.avatar.url} alt="" />
              ) : (
                initialsFor(user.data.name)
              )}
            </span>
            <input
              ref={file}
              className="settings-file-input"
              type="file"
              accept="image/jpeg,image/png,image/webp"
              onChange={(event) => {
                const selected = event.target.files?.[0];
                if (selected)
                  avatar.upload.mutate(selected, {
                    onSuccess: () => {
                      onNotice({ title: "Profile photo updated" });
                    },
                  });
                event.target.value = "";
              }}
            />
            <button
              type="button"
              disabled={avatar.upload.isPending}
              onClick={() => file.current?.click()}
            >
              {avatar.upload.isPending ? "Changing…" : "Change photo"}
            </button>
            {user.data.avatar && (
              <button
                type="button"
                className="settings-remove-photo"
                disabled={avatar.remove.isPending}
                onClick={() => {
                  avatar.remove.mutate(undefined, {
                    onSuccess: () => {
                      onNotice({ title: "Profile photo removed" });
                    },
                  });
                }}
              >
                Remove
              </button>
            )}
          </div>
          <div className="settings-form-stack">
            <label>
              Display name
              <input
                value={name}
                onChange={(event) => {
                  setName(event.target.value);
                }}
                required
              />
              {fieldErrors.name && (
                <small className="settings-error">{fieldErrors.name}</small>
              )}
            </label>
            <label>
              Email address
              <input
                type="email"
                value={emailAddress}
                onChange={(event) => {
                  setEmailAddress(event.target.value);
                }}
                required
              />
              {user.data.pending_email && (
                <small>
                  Confirmation pending for {user.data.pending_email}
                </small>
              )}
              {fieldErrors.email && (
                <small className="settings-error">{fieldErrors.email}</small>
              )}
            </label>
            {emailChanged && (
              <label>
                Current password
                <input
                  type="password"
                  autoComplete="current-password"
                  value={currentPassword}
                  onChange={(event) => {
                    setCurrentPassword(event.target.value);
                  }}
                  required
                />
                <small>Required to safely change your sign-in email.</small>
                {fieldErrors.current_password && (
                  <small className="settings-error">
                    {fieldErrors.current_password}
                  </small>
                )}
              </label>
            )}
            <label>
              About you
              <textarea
                value={about}
                onChange={(event) => {
                  setAbout(event.target.value);
                }}
                rows={4}
              />
              {fieldErrors.about && (
                <small className="settings-error">{fieldErrors.about}</small>
              )}
            </label>
            {message && (profile.isError || email.isError) && (
              <p className="settings-error" role="alert">
                {message}
              </p>
            )}
            {(avatar.upload.isError || avatar.remove.isError) && (
              <p className="settings-error" role="alert">
                Your account photo could not be changed.
              </p>
            )}
            <Button
              variant="primary"
              type="submit"
              disabled={profile.isPending || email.isPending}
            >
              Save changes
            </Button>
          </div>
        </form>
      </section>
      <div>
        {family.current_user_person_id ? (
          <LinkedPersonCard
            familySlug={family.slug}
            personId={family.current_user_person_id}
          />
        ) : (
          <section className="settings-card person-link-card">
            <h3>Your Person page</h3>
            <p>Your account is not linked to a Person in this Family Space.</p>
          </section>
        )}
        <NotificationSettings familySlug={family.slug} onNotice={onNotice} />
      </div>
    </div>
  );
}

function LinkedPersonCard({
  familySlug,
  personId,
}: {
  familySlug: string;
  personId: string;
}) {
  const person = usePersonQuery(familySlug, personId);
  const base = `/families/${encodeURIComponent(familySlug)}`;
  return (
    <section className="settings-card person-link-card">
      <h3>Your Person page</h3>
      {person.isPending && <p role="status">Loading your linked Person…</p>}
      {person.isError && (
        <p role="alert">Your linked Person could not be loaded.</p>
      )}
      {person.data && (
        <>
          <p>
            Your account is linked to {person.data.preferred_name} in the family
            tree.
          </p>
          <ButtonLink to={`${base}/people/${encodeURIComponent(personId)}`}>
            View Person page
          </ButtonLink>
        </>
      )}
    </section>
  );
}

function NotificationSettings({
  familySlug,
  onNotice,
}: {
  familySlug: string;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const preferences = useNotificationPresentationPreferencesQuery(familySlug);
  const update = useUpdateNotificationPresentationPreferences(familySlug);
  const rows: Array<{
    key: NotificationPresentationPreference["key"];
    title: string;
    sub: string;
    icon: "bell" | "image";
  }> = [
    {
      key: "family_activity",
      title: "Family activity",
      sub: "New stories, comments and mentions",
      icon: "bell",
    },
    {
      key: "photo_memories",
      title: "Photo memories",
      sub: "On this day and newly scanned photos",
      icon: "image",
    },
  ];
  return (
    <section className="settings-card" aria-labelledby="notifications-title">
      <h3 id="notifications-title">Notifications</h3>
      {preferences.isPending && (
        <p role="status">Loading notification preferences…</p>
      )}
      {preferences.isError && (
        <p role="alert">Notification preferences could not be loaded.</p>
      )}
      {rows.map((row) => {
        const preference = preferences.data?.find(
          (item) => item.key === row.key && item.channel === "in_app",
        );
        return (
          <SettingRow
            key={row.key}
            icon={<SettingsIcon name={row.icon} />}
            title={row.title}
            sub={row.sub}
          >
            <MixedCheckbox
              label={`${row.title} in-app notifications`}
              state={preference?.state ?? "off"}
              disabled={!preference || update.isPending}
              onChange={(enabled) => {
                update.mutate([{ key: row.key, channel: "in_app", enabled }], {
                  onSuccess: () => {
                    onNotice({
                      title: "Notification preference updated",
                      description: `${row.title} notifications are now ${enabled ? "on" : "off"}.`,
                    });
                  },
                });
              }}
            />
          </SettingRow>
        );
      })}
      {update.isError && (
        <p className="settings-error" role="alert">
          That notification preference could not be saved.
        </p>
      )}
    </section>
  );
}

function MixedCheckbox({
  label,
  state,
  disabled,
  onChange,
}: {
  label: string;
  state: "on" | "off" | "mixed";
  disabled: boolean;
  onChange: (enabled: boolean) => void;
}) {
  const input = useRef<HTMLInputElement>(null);
  useEffect(() => {
    if (input.current) input.current.indeterminate = state === "mixed";
  }, [state]);
  return (
    <input
      ref={input}
      className="settings-toggle"
      type="checkbox"
      aria-label={label}
      checked={state === "on"}
      disabled={disabled}
      onChange={(event) => {
        onChange(event.target.checked);
      }}
    />
  );
}

function AppearanceSettings({
  preference,
  setPreference,
  onNotice,
}: {
  preference: AppearancePreference;
  setPreference: (preference: AppearancePreference) => void;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const options: Array<{
    value: AppearancePreference;
    title: string;
    description: string;
    icon: "monitor" | "sun" | "moon";
  }> = [
    {
      value: "system",
      title: "System",
      description: "Follow this device’s appearance setting.",
      icon: "monitor",
    },
    {
      value: "light",
      title: "Light",
      description: "Use Fambam’s cream and paper theme.",
      icon: "sun",
    },
    {
      value: "dark",
      title: "Dark",
      description: "Use the warm archival dark theme.",
      icon: "moon",
    },
  ];
  return (
    <div className="settings-grid appearance-settings-grid">
      <section className="settings-card appearance-card">
        <h2>Appearance</h2>
        <p>Choose how Fambam looks on this device.</p>
        <fieldset className="theme-options">
          <legend>Theme</legend>
          {options.map((option) => (
            <label
              key={option.value}
              className={preference === option.value ? "selected" : undefined}
            >
              <input
                type="radio"
                name="theme"
                value={option.value}
                checked={preference === option.value}
                onChange={() => {
                  setPreference(option.value);
                  onNotice({
                    title: "Appearance updated",
                    description: `${option.title} theme selected.`,
                  });
                }}
              />
              <span className="theme-option-icon">
                <SettingsIcon name={option.icon} />
              </span>
              <span>
                <b>{option.title}</b>
                <small>{option.description}</small>
              </span>
              <SettingsIcon name="check" />
            </label>
          ))}
        </fieldset>
      </section>
      <aside className="settings-card appearance-note">
        <span className="theme-option-icon">
          <ShellIcon name="palette" />
        </span>
        <div>
          <h3>Same Fambam, different light.</h3>
          <p>
            Photographs, editorial typography and the familiar cognac and
            powder-blue accents stay unchanged in every theme.
          </p>
        </div>
      </aside>
    </div>
  );
}

function SecuritySettings({
  onNotice,
}: {
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const user = useCurrentUserQuery();
  const recent = useRecentSignInsQuery();
  const latest = recent.data?.[0];
  return (
    <div className="settings-grid">
      <section className="settings-card settings-security-card">
        <h2>Account &amp; security</h2>
        <PasswordChangeForm
          submitLabel="Update password"
          onSuccess={() => {
            onNotice({
              title: "Password updated",
              description:
                "You’re still signed in here. Your other sessions have been ended.",
            });
          }}
        />
      </section>
      <div>
        <section className="settings-card settings-protection-card">
          <h3>Sign-in protection</h3>
          {user.isPending && <p role="status">Loading account security…</p>}
          {user.isError && (
            <p role="alert">Account security could not be loaded.</p>
          )}
          {user.data && (
            <>
              <SettingRow
                icon={<SettingsIcon name="shield" />}
                title="Two-step verification"
                sub={
                  user.data.two_factor_enabled
                    ? "On · Authenticator app"
                    : "Off"
                }
              />
              <TwoFactorPanel
                enabled={user.data.two_factor_enabled}
                onNotice={(title, description) => {
                  onNotice({ title, description });
                }}
              />
            </>
          )}
          <SettingRow
            icon={<SettingsIcon name="clock" />}
            title="Recent sign-in"
            sub={
              recent.isPending
                ? "Loading…"
                : recent.isError
                  ? "Unavailable"
                  : latest
                    ? `${formatRelativeDate(latest.signed_in_at)} · ${latest.device}`
                    : "No recent sign-ins"
            }
          />
        </section>
      </div>
    </div>
  );
}

function FamilySettings({
  family,
  onNotice,
}: {
  family: FamilySpace;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const navigate = useNavigate();
  const canUpdate = family.permissions.can_update_family_settings;
  const actions = useFamilySettingsMutations(family.slug);
  const [name, setName] = useState(family.name);
  const [description, setDescription] = useState(family.description ?? "");
  const [visibility, setVisibility] = useState(family.default_visibility);
  const [message, setMessage] = useState("");
  const [leaveOpen, setLeaveOpen] = useState(false);

  useEffect(() => {
    // Keep the editable draft aligned after a successful canonical refetch.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    setName(family.name);
    setDescription(family.description ?? "");
    setVisibility(family.default_visibility);
  }, [family]);

  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setMessage("");
    try {
      await actions.update.mutateAsync({
        name: name.trim(),
        description: description.trim() || null,
        default_visibility: visibility,
      });
      onNotice({ title: "Family settings saved" });
    } catch {
      setMessage("Family settings could not be saved.");
    }
  }

  return (
    <div className="settings-grid">
      <section className="settings-card">
        <h2>{family.name}</h2>
        {!canUpdate && (
          <p role="status">
            Only Owners and Administrators can change family settings.
          </p>
        )}
        <form
          className="settings-form-stack"
          onSubmit={(event) => void submit(event)}
        >
          <label>
            Family name
            <input
              value={name}
              onChange={(event) => {
                setName(event.target.value);
              }}
              disabled={!canUpdate}
              required
            />
          </label>
          <label>
            Family description
            <textarea
              rows={4}
              value={description}
              onChange={(event) => {
                setDescription(event.target.value);
              }}
              disabled={!canUpdate}
            />
          </label>
          <label>
            Default privacy
            <select
              value={visibility}
              onChange={(event) => {
                setVisibility(
                  event.target.value as FamilySpace["default_visibility"],
                );
              }}
              disabled={!canUpdate}
            >
              <option value="family_space">Family members only</option>
              <option value="private">Only me</option>
            </select>
          </label>
          {message && actions.update.isError && (
            <p className="settings-error" role="alert">
              {message}
            </p>
          )}
          {canUpdate && (
            <Button
              variant="primary"
              type="submit"
              disabled={actions.update.isPending}
            >
              Save settings
            </Button>
          )}
        </form>
      </section>
      <ContributorsCard family={family} onNotice={onNotice} />
      {family.permissions.can_leave_family && (
        <section className="settings-card settings-danger-card">
          <h3>Leave family</h3>
          <p>
            Your access will be removed, while your linked Person and family
            memories remain in the archive.
          </p>
          <Button
            variant="danger"
            onClick={() => {
              setLeaveOpen(true);
            }}
          >
            Leave family…
          </Button>
          {actions.leave.isError && (
            <p className="settings-error" role="alert">
              You could not leave this family. Try again.
            </p>
          )}
          <ConfirmDialog
            open={leaveOpen}
            title={`Leave ${family.name}?`}
            confirmLabel="Leave family"
            destructive
            pending={actions.leave.isPending}
            onCancel={() => {
              setLeaveOpen(false);
            }}
            onConfirm={() => {
              actions.leave.mutate(undefined, {
                onSuccess: () => {
                  void navigate("/account", { replace: true });
                },
              });
            }}
          >
            <p>You will lose access to this Family Space.</p>
          </ConfirmDialog>
        </section>
      )}
    </div>
  );
}

function ContributorsCard({
  family,
  onNotice,
}: {
  family: FamilySpace;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const canRead = family.permissions.can_manage_members;
  const memberships = useFamilySpaceMembershipsQuery(family.slug, canRead);
  return (
    <div>
      <section className="settings-card">
        <h3>Who can contribute?</h3>
        <SettingRow
          icon={<ShellIcon name="users" />}
          title="Family members"
          sub={
            memberships.data
              ? `${String(memberships.data.filter((item) => item.state === "active").length)} active members`
              : canRead
                ? "Loading members…"
                : "Managed by Owners and Administrators"
          }
        />
        {family.permissions.can_manage_invitations ? (
          <InvitationSummary familySlug={family.slug} onNotice={onNotice} />
        ) : (
          <SettingRow
            icon={<SettingsIcon name="user-plus" />}
            title="Invitations"
            sub="Managed by Owners and Administrators"
          />
        )}
      </section>
    </div>
  );
}

function InvitationSummary({
  familySlug,
  onNotice,
}: {
  familySlug: string;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const invitations = useInvitationsQuery(familySlug);
  const transition = useTransitionInvitationMutation(familySlug);
  const pending = invitations.data?.filter((item) => item.acceptable).length;
  return (
    <div className="settings-invitations">
      <SettingRow
        icon={<SettingsIcon name="user-plus" />}
        title="Invitations"
        sub={
          invitations.isPending
            ? "Loading invitations…"
            : invitations.isError
              ? "Invitations unavailable"
              : `${String(pending ?? 0)} invitation${pending === 1 ? "" : "s"} waiting`
        }
      />
      {invitations.data
        ?.filter((invitation) => invitation.acceptable)
        .map((invitation) => (
          <div className="settings-invitation-row" key={invitation.id}>
            <span>
              <b>{invitation.email}</b>
              <small>
                {roleLabel(invitation.role)} · Expires{" "}
                {formatDate(invitation.expires_at)}
              </small>
            </span>
            <ContextMenu
              label={`Actions for invitation to ${invitation.email}`}
            >
              <button
                type="button"
                disabled={transition.isPending}
                onClick={() => {
                  transition.mutate(
                    {
                      invitationId: invitation.id,
                      action: "resend",
                    },
                    {
                      onSuccess: () => {
                        onNotice({
                          title: `Invitation resent to ${invitation.email}`,
                        });
                      },
                    },
                  );
                }}
              >
                Resend invitation
              </button>
              <button
                type="button"
                className="ui-context-menu__danger"
                disabled={transition.isPending}
                onClick={() => {
                  transition.mutate(
                    {
                      invitationId: invitation.id,
                      action: "revoke",
                    },
                    {
                      onSuccess: () => {
                        onNotice({
                          title: `Invitation to ${invitation.email} revoked`,
                        });
                      },
                    },
                  );
                }}
              >
                Revoke invitation
              </button>
            </ContextMenu>
          </div>
        ))}
      {transition.isError && (
        <p className="settings-error" role="alert">
          That invitation could not be changed.
        </p>
      )}
    </div>
  );
}

function FamilyOverview({
  family,
  onNotice,
}: {
  family: FamilySpace;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const overview = useSettingsOverviewQuery(family.slug);
  const canManage = family.permissions.can_manage_members;
  const memberships = useFamilySpaceMembershipsQuery(family.slug, canManage);
  const [inviteOpen, setInviteOpen] = useState(false);
  if (overview.isPending) return <p role="status">Loading family overview…</p>;
  if (overview.isError)
    return <p role="alert">The family overview could not be loaded.</p>;
  const stats = [
    ["People", overview.data.counts.people],
    ["Photographs", overview.data.counts.photos],
    ["Albums", overview.data.counts.albums],
    ["Stories", overview.data.counts.stories],
  ] as const;
  return (
    <div className="settings-overview">
      <div className="settings-stats-grid">
        {stats.map(([label, value]) => (
          <div key={label}>
            <b>{value.toLocaleString()}</b>
            <span>{label}</span>
          </div>
        ))}
      </div>
      <div className="settings-grid">
        <section className="settings-card settings-member-card">
          <div className="settings-card-head">
            <div>
              <h2>Family members</h2>
              <p>Roles control access across the {family.name} Family Space.</p>
            </div>
            {family.permissions.can_manage_invitations && (
              <Button
                variant="primary"
                onClick={() => {
                  setInviteOpen(true);
                }}
              >
                <SettingsIcon name="user-plus" />
                Invite people
              </Button>
            )}
          </div>
          {!canManage && (
            <p role="status">
              Member management is available to Owners and Administrators.
            </p>
          )}
          {memberships.isPending && (
            <p role="status">Loading family members…</p>
          )}
          {memberships.isError && (
            <p role="alert">Family members could not be loaded.</p>
          )}
          {memberships.data
            ?.filter((item) => item.state === "active")
            .map((member) => (
              <MemberRow
                key={member.id}
                family={family}
                member={member}
                onNotice={onNotice}
              />
            ))}
        </section>
        <aside>
          <section className="settings-card">
            <h3>Archive health</h3>
            <HealthRow
              label="Photos dated"
              metric={overview.data.archive_health.photos_dated}
            />
            <HealthRow
              label="Faces identified"
              metric={overview.data.archive_health.faces_identified}
            />
            <HealthRow
              label="People connected"
              metric={overview.data.archive_health.people_connected}
            />
          </section>
        </aside>
      </div>
      <InvitePeopleDialog
        family={family}
        open={inviteOpen}
        onClose={() => {
          setInviteOpen(false);
        }}
        onNotice={onNotice}
      />
    </div>
  );
}

function MemberRow({
  family,
  member,
  onNotice,
}: {
  family: FamilySpace;
  member: FamilyMembership;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const actions = useFamilySpaceMembershipMutations(family.slug);
  const familyActions = useFamilySettingsMutations(family.slug);
  const [roleOpen, setRoleOpen] = useState(false);
  const [role, setRole] = useState<FamilySpaceRole>(member.role);
  const [confirm, setConfirm] = useState<
    "remove" | "transfer" | "leave" | null
  >(null);
  const [details, setDetails] = useState(false);
  const base = `/families/${encodeURIComponent(family.slug)}`;
  const canChange =
    family.permissions.can_manage_members &&
    !member.is_current_user &&
    member.role !== "owner";
  const canRemove = canChange;
  const canTransfer =
    family.permissions.can_transfer_ownership &&
    member.role === "administrator";
  const canLeave =
    member.is_current_user && family.permissions.can_leave_family;
  async function confirmAction() {
    if (confirm === "remove") {
      await actions.remove.mutateAsync(member.id);
      onNotice({ title: `${member.user.name} removed from the family` });
    }
    if (confirm === "transfer") {
      await familyActions.transferOwnership.mutateAsync(member.id);
      onNotice({ title: `Ownership transferred to ${member.user.name}` });
    }
    if (confirm === "leave") {
      await familyActions.leave.mutateAsync();
      onNotice({ title: `You left ${family.name}` });
    }
    setConfirm(null);
  }
  const pending =
    actions.remove.isPending ||
    familyActions.transferOwnership.isPending ||
    familyActions.leave.isPending;
  return (
    <div className="settings-member-row">
      <PersonAvatar
        name={member.user.name}
        portraitUrl={member.user.avatar?.url ?? undefined}
      />
      <span className="settings-member-identity">
        <b>
          {member.user.name}
          {member.is_current_user && <em>You</em>}
        </b>
        <small>
          {member.linked_person ? "Linked to Person · " : "No linked Person · "}
          Joined {formatDate(member.joined_at)}
        </small>
      </span>
      <span className={`settings-member-role role-${member.role}`}>
        {roleLabel(member.role)}
      </span>
      <ContextMenu
        label={`Actions for ${member.user.name}`}
        panelClassName="settings-member-menu"
      >
        {member.linked_person && (
          <Link
            to={`${base}/people/${encodeURIComponent(member.linked_person.id)}`}
          >
            {member.is_current_user
              ? "View my Person page"
              : "View Person page"}
          </Link>
        )}
        {canChange && (
          <button
            type="button"
            onClick={() => {
              setRole(member.role);
              setRoleOpen(true);
            }}
          >
            Change role…
          </button>
        )}
        <button
          type="button"
          onClick={() => {
            setDetails(true);
          }}
        >
          View membership details
        </button>
        {(canTransfer || canRemove || canLeave) && <hr />}
        {canTransfer && (
          <button
            type="button"
            onClick={() => {
              setConfirm("transfer");
            }}
          >
            Transfer ownership…
          </button>
        )}
        {canLeave && (
          <button
            className="ui-context-menu__danger"
            type="button"
            onClick={() => {
              setConfirm("leave");
            }}
          >
            Leave family…
          </button>
        )}
        {canRemove && (
          <button
            className="ui-context-menu__danger"
            type="button"
            onClick={() => {
              setConfirm("remove");
            }}
          >
            Remove from family…
          </button>
        )}
      </ContextMenu>
      <Dialog
        open={roleOpen}
        title={`Change ${member.user.name}’s role`}
        eyebrow="Family role"
        description="Choose one role. Permissions stay clear and consistent across the Family Space."
        onClose={() => {
          setRoleOpen(false);
        }}
      >
        <div className="settings-role-options">
          {(
            [
              "administrator",
              "member",
              "contributor",
              "guest",
            ] as FamilySpaceRole[]
          ).map((value) => (
            <label
              key={value}
              className={role === value ? "selected" : undefined}
            >
              <input
                type="radio"
                name={`role-${member.id}`}
                aria-label={roleLabel(value)}
                checked={role === value}
                onChange={() => {
                  setRole(value);
                }}
              />
              <span>
                <b>{roleLabel(value)}</b>
                <small>{roleDescription(value)}</small>
              </span>
            </label>
          ))}
        </div>
        <div className="ui-inline-actions">
          <Button
            onClick={() => {
              setRoleOpen(false);
            }}
          >
            Cancel
          </Button>
          <Button
            variant="primary"
            disabled={actions.update.isPending}
            onClick={() => {
              actions.update.mutate(
                { membershipId: member.id, role },
                {
                  onSuccess: () => {
                    setRoleOpen(false);
                    onNotice({
                      title: "Member role updated",
                      description: `${member.user.name} is now ${roleLabel(role)}.`,
                    });
                  },
                },
              );
            }}
          >
            Save role
          </Button>
        </div>
      </Dialog>
      <Dialog
        open={details}
        title="Membership details"
        eyebrow="Family member"
        onClose={() => {
          setDetails(false);
        }}
      >
        <dl className="settings-details">
          <dt>Name</dt>
          <dd>{member.user.name}</dd>
          <dt>Email</dt>
          <dd>{member.user.email}</dd>
          <dt>Role</dt>
          <dd>{roleLabel(member.role)}</dd>
          <dt>Joined</dt>
          <dd>{formatDate(member.joined_at)}</dd>
          <dt>Person link</dt>
          <dd>{member.linked_person?.display_name ?? "Not linked"}</dd>
        </dl>
      </Dialog>
      <ConfirmDialog
        open={confirm !== null}
        title={
          confirm === "transfer"
            ? `Transfer ownership to ${member.user.name}?`
            : confirm === "leave"
              ? `Leave ${family.name}?`
              : `Remove ${member.user.name} from the family?`
        }
        confirmLabel={
          confirm === "transfer"
            ? "Transfer ownership"
            : confirm === "leave"
              ? "Leave family"
              : "Remove from family"
        }
        destructive={confirm !== "transfer"}
        pending={pending}
        onCancel={() => {
          setConfirm(null);
        }}
        onConfirm={() => void confirmAction()}
      >
        <p>
          {confirm === "transfer"
            ? "Your role will change to Administrator after the transfer."
            : confirm === "leave"
              ? "You will lose access. Your linked Person and family memories will remain."
              : "They will lose access. Their linked Person and family memories will remain."}
        </p>
      </ConfirmDialog>
    </div>
  );
}

function InvitePeopleDialog({
  family,
  open,
  onClose,
  onNotice,
}: {
  family: FamilySpace;
  open: boolean;
  onClose: () => void;
  onNotice: (notice: ActionNoticeMessage) => void;
}) {
  const invitation = useIssueInvitationMutation(family.slug);
  const [email, setEmail] = useState("");
  const [role, setRole] = useState<Invitation["role"]>("member");
  const [error, setError] = useState("");
  async function submit(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    try {
      await invitation.mutateAsync({ email, role });
      onNotice({
        title: "Invitation sent",
        description: `An invitation was sent to ${email}.`,
      });
      setEmail("");
      onClose();
    } catch (requestError) {
      const fields = toLaravelFieldErrors(requestError);
      const status = toAppError(requestError).status;
      setError(
        fields.invitation ??
          fields.email ??
          (status === 419
            ? "Your session expired. Refresh the page and try again."
            : status === 429
              ? "Too many invitations have been sent. Try again later."
              : "That invitation could not be sent."),
      );
    }
  }
  return (
    <Dialog
      open={open}
      title="Invite people"
      eyebrow="Family invitation"
      description={`Invite someone to ${family.name}.`}
      pending={invitation.isPending}
      onClose={onClose}
    >
      <form
        className="settings-form-stack"
        onSubmit={(event) => void submit(event)}
      >
        <label>
          Email address
          <input
            type="email"
            autoComplete="email"
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
            }}
            required
            data-autofocus
          />
        </label>
        <label>
          Role
          <select
            value={role}
            onChange={(event) => {
              setRole(event.target.value as Invitation["role"]);
            }}
          >
            <option value="administrator">Admin</option>
            <option value="member">Member</option>
            <option value="contributor">Contributor</option>
            <option value="guest">Guest</option>
          </select>
        </label>
        {error && (
          <p className="settings-error" role="alert">
            {error}
          </p>
        )}
        <div className="ui-inline-actions">
          <Button onClick={onClose}>Cancel</Button>
          <Button
            variant="primary"
            type="submit"
            disabled={invitation.isPending}
          >
            Send invitation
          </Button>
        </div>
      </form>
    </Dialog>
  );
}

function HealthRow({
  label,
  metric,
}: {
  label: string;
  metric: ArchiveHealthMetric;
}) {
  const value = metric.percentage;
  return (
    <div className="settings-health">
      <span>{label}</span>
      <b>{value === null ? "—" : `${String(Math.round(value))}%`}</b>
      <progress max={100} value={value ?? undefined} aria-label={label} />
      <small>
        {metric.numerator.toLocaleString()} of{" "}
        {metric.denominator.toLocaleString()}
      </small>
    </div>
  );
}

function SettingRow({
  icon,
  title,
  sub,
  children,
}: {
  icon: ReactNode;
  title: string;
  sub: string;
  children?: ReactNode;
}) {
  return (
    <div className="setting-row">
      <span>{icon}</span>
      <div>
        <b>{title}</b>
        <small>{sub}</small>
      </div>
      {children}
    </div>
  );
}

function SettingsIcon({
  name,
}: {
  name:
    | "bell"
    | "image"
    | "monitor"
    | "sun"
    | "moon"
    | "check"
    | "shield"
    | "clock"
    | "user-plus";
}) {
  const paths: Record<typeof name, ReactNode> = {
    bell: (
      <>
        <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9" />
        <path d="M10 21h4" />
      </>
    ),
    image: (
      <>
        <rect x="3" y="4" width="18" height="16" rx="2" />
        <circle cx="8.5" cy="9" r="1.5" />
        <path d="m21 15-5-5L5 20" />
      </>
    ),
    monitor: (
      <>
        <rect x="3" y="4" width="18" height="13" rx="2" />
        <path d="M8 21h8m-4-4v4" />
      </>
    ),
    sun: (
      <>
        <circle cx="12" cy="12" r="4" />
        <path d="M12 2v2m0 16v2M4.93 4.93l1.42 1.42m11.3 11.3 1.42 1.42M2 12h2m16 0h2M4.93 19.07l1.42-1.42m11.3-11.3 1.42-1.42" />
      </>
    ),
    moon: <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79" />,
    check: <path d="m5 12 4 4L19 6" />,
    shield: (
      <>
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10" />
        <path d="m9 12 2 2 4-4" />
      </>
    ),
    clock: (
      <>
        <circle cx="12" cy="12" r="9" />
        <path d="M12 7v5l3 2" />
      </>
    ),
    "user-plus": (
      <>
        <path d="M15 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
        <circle cx="8.5" cy="7" r="4" />
        <path d="M19 8v6m-3-3h6" />
      </>
    ),
  };
  return (
    <svg
      aria-hidden="true"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.8"
      strokeLinecap="round"
      strokeLinejoin="round"
    >
      {paths[name]}
    </svg>
  );
}

function initialsFor(name: string) {
  return name
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0].toUpperCase())
    .join("");
}
function formatDate(value: string) {
  return new Intl.DateTimeFormat(undefined, {
    day: "numeric",
    month: "short",
    year: "numeric",
  }).format(new Date(value));
}
function formatRelativeDate(value: string) {
  const date = new Date(value);
  return date.toDateString() === new Date().toDateString()
    ? "Today"
    : formatDate(value);
}
function roleLabel(role: FamilySpaceRole) {
  return role === "administrator"
    ? "Admin"
    : role.charAt(0).toUpperCase() + role.slice(1);
}
function roleDescription(role: FamilySpaceRole) {
  if (role === "administrator")
    return "Manages members and Family Space settings";
  if (role === "member") return "Full access to family memories";
  if (role === "contributor") return "Can add and edit their contributions";
  return "Limited invited access";
}
