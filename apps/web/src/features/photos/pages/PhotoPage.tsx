import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";
import { usePeopleQuery } from "@/features/people/hooks/usePeopleQuery";

import { PhotoForm } from "../components/PhotoForm";
import { PhotoFamilyMetadataProposals } from "../components/PhotoFamilyMetadataProposals";
import { PhotoMetadataForm } from "../components/PhotoMetadataForm";
import { PhotoPersonForm } from "../components/PhotoPersonForm";
import { PhotoProvenanceForm } from "../components/PhotoProvenanceForm";
import { PhotoProvenanceProposals } from "../components/PhotoProvenanceProposals";
import { PhotoTagsForm } from "../components/PhotoTagsForm";
import {
  useReplacePhotoTagsMutation,
  useSubmitPhotoMetadataMutation,
  useSubmitPhotoPersonMutation,
  useSubmitPhotoProvenanceMutation,
  useUpdatePhotoMutation,
} from "../hooks/usePhotoMutations";
import { usePhotoQuery } from "../hooks/usePhotoQueries";

export function PhotoPage() {
  const { familySlug = "", photoId = "" } = useParams();
  const photoQuery = usePhotoQuery(familySlug, photoId);
  const peopleQuery = usePeopleQuery(familySlug);
  const updatePhoto = useUpdatePhotoMutation(familySlug, photoId);
  const replaceTags = useReplacePhotoTagsMutation(familySlug, photoId);
  const submitProvenance = useSubmitPhotoProvenanceMutation(
    familySlug,
    photoId,
  );
  const submitMetadata = useSubmitPhotoMetadataMutation(familySlug, photoId);
  const submitPerson = useSubmitPhotoPersonMutation(familySlug, photoId);
  const notFound =
    photoQuery.isError && toAppError(photoQuery.error).status === 404;

  if (photoQuery.isPending) return <p role="status">Loading Photo…</p>;
  if (notFound)
    return (
      <p role="alert">
        This Photo is unavailable or you no longer have access.
      </p>
    );
  if (photoQuery.isError)
    return <p role="alert">The Photo record could not be loaded.</p>;

  const photo = photoQuery.data;

  return (
    <main className="auth people" aria-labelledby="photo-title">
      <p className="eyebrow">
        {photo.visibility === "private" ? "Private Photo" : "Family Photo"}
      </p>
      <h1 id="photo-title">
        {photo.caption ?? photo.media_upload.client_filename}
      </h1>
      {photo.description !== null && <p>{photo.description}</p>}
      <dl className="photo-details">
        <div>
          <dt>Uploaded by</dt>
          <dd>{photo.media_upload.uploader?.name ?? "Former account"}</dd>
        </div>
        <div>
          <dt>Archive source</dt>
          <dd>{photo.archive_source_description ?? "Not recorded"}</dd>
        </div>
        <div>
          <dt>Historical date</dt>
          <dd>{formatDate(photo.historical_date)}</dd>
        </div>
        <div>
          <dt>Location</dt>
          <dd>{photo.location_description ?? "Not recorded"}</dd>
        </div>
        <div>
          <dt>Photographer</dt>
          <dd>{formatClaim(photo.provenance.photographer)}</dd>
        </div>
        <div>
          <dt>Scanner</dt>
          <dd>{formatClaim(photo.provenance.scanner)}</dd>
        </div>
        <div>
          <dt>Original physical owner</dt>
          <dd>{formatClaim(photo.provenance.physical_owner)}</dd>
        </div>
        <div>
          <dt>Tags</dt>
          <dd>{photo.tags.map((tag) => tag.label).join(", ") || "None"}</dd>
        </div>
        <div>
          <dt>People appearing</dt>
          <dd>
            {photo.people
              .map((association) => association.person.preferred_name)
              .join(", ") || "None confirmed"}
          </dd>
        </div>
      </dl>

      {photo.permissions.can_update && (
        <section aria-labelledby="edit-photo-title">
          <h2 id="edit-photo-title">Edit Photo</h2>
          <PhotoForm
            photo={photo}
            pending={updatePhoto.isPending}
            onSubmit={(input) => updatePhoto.mutateAsync(input)}
          />
        </section>
      )}

      {photo.permissions.can_manage_tags && (
        <section aria-labelledby="photo-tags-title">
          <h2 id="photo-tags-title">Organise with tags</h2>
          <PhotoTagsForm
            initialTags={photo.tags.map((tag) => tag.label)}
            pending={replaceTags.isPending}
            onSubmit={(tags) => replaceTags.mutateAsync(tags)}
          />
        </section>
      )}

      {photo.permissions.can_propose_provenance && (
        <section aria-labelledby="photo-family-metadata-title">
          <h2 id="photo-family-metadata-title">Family-supplied metadata</h2>
          <PhotoMetadataForm
            pending={submitMetadata.isPending}
            onSubmit={(input) => submitMetadata.mutateAsync(input)}
          />
          {peopleQuery.isPending && <p role="status">Loading People…</p>}
          {peopleQuery.isError && (
            <p role="alert">People could not be loaded for this Photo.</p>
          )}
          {peopleQuery.data !== undefined && (
            <PhotoPersonForm
              people={peopleQuery.data}
              pending={submitPerson.isPending}
              onSubmit={(personId) => submitPerson.mutateAsync(personId)}
            />
          )}
        </section>
      )}

      {photo.permissions.can_propose_provenance && (
        <section aria-labelledby="photo-provenance-title">
          <h2 id="photo-provenance-title">Photo provenance</h2>
          {peopleQuery.isPending && <p role="status">Loading People…</p>}
          {peopleQuery.isError && (
            <p role="alert">People could not be loaded for provenance.</p>
          )}
          {peopleQuery.data !== undefined && (
            <PhotoProvenanceForm
              people={peopleQuery.data}
              pending={submitProvenance.isPending}
              onSubmit={(input) => submitProvenance.mutateAsync(input)}
            />
          )}
        </section>
      )}

      {photo.permissions.can_resolve_provenance && (
        <section aria-labelledby="photo-family-proposals-title">
          <h2 id="photo-family-proposals-title">
            Pending family metadata proposals
          </h2>
          <PhotoFamilyMetadataProposals
            familySlug={familySlug}
            photoId={photoId}
          />
        </section>
      )}

      {photo.permissions.can_resolve_provenance && (
        <section aria-labelledby="photo-proposals-title">
          <h2 id="photo-proposals-title">Pending provenance proposals</h2>
          <PhotoProvenanceProposals familySlug={familySlug} photoId={photoId} />
        </section>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}/photos`}>
        Back to photographs
      </Link>
    </main>
  );
}

function formatDate(
  date: { precision: string; value: string | null } | null,
): string {
  if (date === null) return "Not recorded";
  if (date.value === null) return "Unknown";
  return `${date.precision.replaceAll("_", " ")}: ${date.value}`;
}

function formatClaim(claim: {
  person: { preferred_name: string } | null;
  description: string | null;
}): string {
  return claim.person?.preferred_name ?? claim.description ?? "Not recorded";
}
