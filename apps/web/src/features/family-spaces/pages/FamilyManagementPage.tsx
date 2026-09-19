import { Link, useParams } from "react-router";

import { InvitationManagement } from "@/features/invitations/pages/InvitationManagement";

import { FamilySpaceDeletionPanel } from "../components/FamilySpaceDeletionPanel";
import { useFamilySpaceQuery } from "../hooks/useFamilySpaceQuery";
import {
  useFamilySpaceMembershipMutations,
  useFamilySpaceMembershipsQuery,
} from "../hooks/useFamilyMemberships";
import type { FamilySpaceRole } from "../types/familySpace";

const roles: FamilySpaceRole[] = [
  "owner",
  "administrator",
  "member",
  "contributor",
  "guest",
];

export function FamilyManagementPage() {
  const { familySlug = "" } = useParams();
  const family = useFamilySpaceQuery(familySlug);
  const canManage =
    family.data?.role === "owner" || family.data?.role === "administrator";
  const memberships = useFamilySpaceMembershipsQuery(familySlug, canManage);
  const actions = useFamilySpaceMembershipMutations(familySlug);
  if (family.isPending)
    return <p role="status">Loading Family Space settings…</p>;
  if (family.isError)
    return <p role="alert">Family Space settings could not be loaded.</p>;
  if (!canManage)
    return (
      <p role="alert">
        Only Owners and Administrators can manage this Family Space.
      </p>
    );
  return (
    <main className="journey-page" aria-labelledby="family-management-title">
      <p className="eyebrow">Family settings</p>
      <h1 id="family-management-title">Manage {family.data.name}</h1>
      <section aria-labelledby="members-title">
        <h2 id="members-title">Family members</h2>
        {memberships.isPending ? (
          <p role="status">Loading family members…</p>
        ) : memberships.isError ? (
          <p role="alert">Family members could not be loaded.</p>
        ) : (
          <ul className="membership-list">
            {memberships.data
              .filter((item) => item.state === "active")
              .map((item) => (
                <li key={item.id}>
                  <div>
                    <strong>{item.user.name}</strong>
                    <small>{item.user.email}</small>
                  </div>
                  <label>
                    Role for {item.user.name}
                    <select
                      value={item.role}
                      disabled={actions.update.isPending}
                      onChange={(event) => {
                        actions.update.mutate({
                          membershipId: item.id,
                          role: event.target.value as FamilySpaceRole,
                        });
                      }}
                    >
                      {roles.map((role) => (
                        <option key={role} value={role}>
                          {role}
                        </option>
                      ))}
                    </select>
                  </label>
                  <button
                    type="button"
                    disabled={actions.remove.isPending}
                    onClick={() => {
                      if (
                        window.confirm(
                          `Remove ${item.user.name} from this Family Space?`,
                        )
                      )
                        actions.remove.mutate(item.id);
                    }}
                  >
                    Remove
                  </button>
                </li>
              ))}
          </ul>
        )}
        {(actions.update.isError || actions.remove.isError) && (
          <p role="alert">The membership change could not be saved.</p>
        )}
      </section>
      <InvitationManagement familySlug={familySlug} />
      <section aria-labelledby="portability-title">
        <h2 id="portability-title">Portability</h2>
        <p>
          <Link to={`/families/${encodeURIComponent(familySlug)}/exports`}>
            Open exports and downloads
          </Link>
        </p>
      </section>
      <FamilySpaceDeletionPanel familySpace={family.data} />
    </main>
  );
}
