import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";
import { InvitationManagement } from "@/features/invitations/pages/InvitationManagement";

import { useFamilySpaceQuery } from "../hooks/useFamilySpaceQuery";

export function FamilySpacePage() {
  const { familySlug = "" } = useParams();
  const familySpaceQuery = useFamilySpaceQuery(familySlug);
  const familySpace = familySpaceQuery.data;
  const canManageInvitations =
    familySpace?.role === "owner" || familySpace?.role === "administrator";
  const canAccessPeople =
    familySpace?.role === "owner" ||
    familySpace?.role === "administrator" ||
    familySpace?.role === "member";
  const canUploadMedia = canAccessPeople;
  const canAccessAlbums = familySpace?.role !== "guest";
  const notFound =
    familySpaceQuery.isError &&
    toAppError(familySpaceQuery.error).status === 404;

  if (familySpaceQuery.isPending) {
    return <p role="status">Opening Family Space…</p>;
  }

  if (notFound) {
    return (
      <main aria-labelledby="family-not-found-title">
        <h1 id="family-not-found-title">Family Space not found</h1>
        <p>This Family Space is unavailable or you no longer have access.</p>
        <Link to="/account">Return to your account</Link>
      </main>
    );
  }

  if (familySpaceQuery.isError || familySpace === undefined) {
    return <p role="alert">This Family Space could not be loaded.</p>;
  }

  return (
    <main className="auth" aria-labelledby="family-space-title">
      <p className="eyebrow">fambam</p>
      <h1 id="family-space-title">{familySpace.name}</h1>
      <p>Your role: {familySpace.role}</p>
      {canAccessPeople && (
        <p>
          <Link to={`/families/${encodeURIComponent(familySpace.slug)}/people`}>
            Open people directory
          </Link>
        </p>
      )}
      {canUploadMedia && (
        <>
          <p>
            <Link
              to={`/families/${encodeURIComponent(familySpace.slug)}/photos`}
            >
              Open photograph archive
            </Link>
          </p>
          <p>
            <Link
              to={`/families/${encodeURIComponent(familySpace.slug)}/uploads`}
            >
              Upload photographs
            </Link>
          </p>
        </>
      )}
      {canAccessAlbums && (
        <p>
          <Link to={`/families/${encodeURIComponent(familySpace.slug)}/albums`}>
            Open albums
          </Link>
        </p>
      )}
      {canManageInvitations ? (
        <InvitationManagement familySlug={familySpace.slug} />
      ) : (
        <p>Invitation management is available to Owners and Administrators.</p>
      )}
      <Link to="/account">Back to your account</Link>
    </main>
  );
}
