import { Link, useParams } from "react-router";

import { PersonForm } from "../components/PersonForm";
import { useCreatePersonMutation } from "../hooks/usePersonMutations";
import { usePeopleQuery } from "../hooks/usePeopleQuery";

export function PeoplePage() {
  const { familySlug = "" } = useParams();
  const peopleQuery = usePeopleQuery(familySlug);
  const createPerson = useCreatePersonMutation(familySlug);

  if (peopleQuery.isPending) {
    return <p role="status">Loading people…</p>;
  }

  if (peopleQuery.isError) {
    return <p role="alert">The people directory could not be loaded.</p>;
  }

  return (
    <main className="auth people" aria-labelledby="people-title">
      <p className="eyebrow">fambam</p>
      <h1 id="people-title">People</h1>
      {peopleQuery.data.length === 0 ? (
        <p>No People have been added to this Family Space yet.</p>
      ) : (
        <ul className="people-list">
          {peopleQuery.data.map((person) => (
            <li key={person.id}>
              <Link
                to={`/families/${encodeURIComponent(familySlug)}/people/${person.id}`}
              >
                {person.preferred_name}
              </Link>
              {person.identity_status === "provisional" && (
                <span>Provisional</span>
              )}
            </li>
          ))}
        </ul>
      )}

      <section aria-labelledby="add-person-title">
        <h2 id="add-person-title">Add a Person</h2>
        <p>
          Members create provisional records. Owners and Administrators create
          confirmed records.
        </p>
        <PersonForm
          submitLabel="Add Person"
          pending={createPerson.isPending}
          successMessage="Person added."
          onSubmit={(input) => createPerson.mutateAsync(input)}
        />
      </section>
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
