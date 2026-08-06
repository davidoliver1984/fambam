import { Link, useParams } from "react-router";

import { toAppError } from "@/api/errors";

import { PersonForm } from "../components/PersonForm";
import { PersonAccountLinkPanel } from "../components/PersonAccountLinkPanel";
import { PersonProposals } from "../components/PersonProposals";
import {
  useProposePersonDetailsMutation,
  useUpdatePersonMutation,
} from "../hooks/usePersonMutations";
import { usePersonQuery } from "../hooks/usePersonQuery";

export function PersonPage() {
  const { familySlug = "", personId = "" } = useParams();
  const personQuery = usePersonQuery(familySlug, personId);
  const updatePerson = useUpdatePersonMutation(familySlug, personId);
  const proposeDetails = useProposePersonDetailsMutation(familySlug, personId);
  const notFound =
    personQuery.isError && toAppError(personQuery.error).status === 404;

  if (personQuery.isPending) {
    return <p role="status">Loading Person…</p>;
  }

  if (notFound) {
    return (
      <p role="alert">
        This Person is unavailable or you no longer have access.
      </p>
    );
  }

  if (personQuery.isError) {
    return <p role="alert">The Person record could not be loaded.</p>;
  }

  const person = personQuery.data;
  const authoritative = person.permissions.can_update_authoritatively;
  const canSubmit = authoritative || person.permissions.can_propose_changes;

  return (
    <main className="auth people" aria-labelledby="person-title">
      <p className="eyebrow">{person.identity_status} Person</p>
      <h1 id="person-title">{person.preferred_name}</h1>
      {person.alternate_names.length > 0 && (
        <p>Also known as {person.alternate_names.join(", ")}</p>
      )}
      <p>
        Born:{" "}
        {formatUncertainDate(
          person.birth_date.precision,
          person.birth_date.value,
        )}
      </p>
      <p>
        {person.is_deceased
          ? `Died: ${formatUncertainDate(person.death_date.precision, person.death_date.value)}`
          : "Living or not marked as deceased"}
      </p>
      {person.biography && <p>{person.biography}</p>}

      <PersonAccountLinkPanel familySlug={familySlug} person={person} />

      {person.permissions.can_resolve_proposals && (
        <section aria-labelledby="proposals-title">
          <h2 id="proposals-title">Pending proposals</h2>
          <PersonProposals familySlug={familySlug} personId={personId} />
        </section>
      )}

      {canSubmit && (
        <section aria-labelledby="edit-person-title">
          <h2 id="edit-person-title">
            {authoritative ? "Edit Person" : "Propose changes"}
          </h2>
          {!authoritative && (
            <p>
              An Owner or Administrator must review Member-proposed changes.
            </p>
          )}
          <PersonForm
            person={person}
            submitLabel={authoritative ? "Save changes" : "Submit proposal"}
            pending={
              authoritative ? updatePerson.isPending : proposeDetails.isPending
            }
            successMessage={
              authoritative
                ? "Person updated."
                : "Proposal submitted for review."
            }
            onSubmit={(input) =>
              authoritative
                ? updatePerson.mutateAsync(input)
                : proposeDetails.mutateAsync(input)
            }
          />
        </section>
      )}
      <Link to={`/families/${encodeURIComponent(familySlug)}/people`}>
        Back to People
      </Link>
    </main>
  );
}

function formatUncertainDate(precision: string, value: string | null): string {
  if (precision === "unknown" || value === null) return "Unknown";
  return precision === "exact" ? value : `${value} (${precision})`;
}
