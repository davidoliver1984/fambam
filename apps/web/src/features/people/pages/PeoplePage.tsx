import { useState } from "react";
import { Link, useParams } from "react-router";

import { useFamilySpaceQuery } from "@/features/family-spaces/hooks/useFamilySpaceQuery";

import { PersonForm } from "../components/PersonForm";
import { FamilyCirclesPanel } from "../components/FamilyCirclesPanel";
import { useCreatePersonMutation } from "../hooks/usePersonMutations";
import { usePeopleQuery } from "../hooks/usePeopleQuery";

export function PeoplePage() {
  const { familySlug = "" } = useParams();
  const peopleQuery = usePeopleQuery(familySlug);
  const family = useFamilySpaceQuery(familySlug);
  const createPerson = useCreatePersonMutation(familySlug);
  const [nameFilter, setNameFilter] = useState("");

  if (peopleQuery.isPending) {
    return <p role="status">Loading people…</p>;
  }

  if (peopleQuery.isError) {
    return <p role="alert">The people directory could not be loaded.</p>;
  }

  const visiblePeople = peopleQuery.data.filter((person) =>
    [person.preferred_name, ...person.alternate_names].some((name) =>
      name.toLocaleLowerCase().includes(nameFilter.trim().toLocaleLowerCase()),
    ),
  );
  const canReviewIdentity =
    family.data?.role === "owner" || family.data?.role === "administrator";

  return (
    <main
      className="journey-page people-journey"
      aria-labelledby="people-title"
    >
      <p className="eyebrow">Family archive</p>
      <div className="journey-heading">
        <div>
          <h1 id="people-title">People</h1>
          <p>Family members and the stories connected to them.</p>
        </div>
        <a className="journey-action" href="#add-person-title">
          Add a person
        </a>
      </div>
      {peopleQuery.data.length === 0 ? (
        <p>No People have been added to this Family Space yet.</p>
      ) : (
        <section aria-labelledby="people-directory-title">
          <h2 id="people-directory-title">Family directory</h2>
          <label htmlFor="people-name-filter">Find a person by name</label>
          <input
            id="people-name-filter"
            type="search"
            value={nameFilter}
            onChange={(event) => {
              setNameFilter(event.target.value);
            }}
          />
          {visiblePeople.length === 0 ? (
            <p>No People match that name.</p>
          ) : (
            <ul className="people-card-grid">
              {visiblePeople.map((person) => (
                <li key={person.id}>
                  <span className="people-avatar" aria-hidden="true">
                    {person.preferred_name.slice(0, 1).toUpperCase()}
                  </span>
                  <Link
                    to={`/families/${encodeURIComponent(familySlug)}/people/${encodeURIComponent(person.id)}`}
                  >
                    {person.preferred_name}
                  </Link>
                  {person.identity_status === "provisional" && (
                    <span>Provisional</span>
                  )}
                  {person.birth_date.value !== null && (
                    <small>Born {person.birth_date.value}</small>
                  )}
                </li>
              ))}
            </ul>
          )}
        </section>
      )}

      {canReviewIdentity && (
        <nav className="people-review-links" aria-label="Identity review">
          <Link
            to={`/families/${encodeURIComponent(familySlug)}/face-clusters`}
          >
            Review unknown face groups
          </Link>
          <Link
            to={`/families/${encodeURIComponent(familySlug)}/face-recognition`}
          >
            Review identity suggestions
          </Link>
        </nav>
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
      <FamilyCirclesPanel familySlug={familySlug} people={peopleQuery.data} />
      <Link to={`/families/${encodeURIComponent(familySlug)}`}>
        Back to Family Space
      </Link>
    </main>
  );
}
