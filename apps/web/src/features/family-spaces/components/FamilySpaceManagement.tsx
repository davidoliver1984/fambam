import { type SyntheticEvent, useState } from "react";

import { InvitationManagement } from "@/features/invitations/pages/InvitationManagement";

import { useCreateFamilySpaceMutation } from "../hooks/useCreateFamilySpaceMutation";
import { useFamilySpacesQuery } from "../hooks/useFamilySpacesQuery";

export function FamilySpaceManagement({ canCreate }: { canCreate: boolean }) {
  const familySpaces = useFamilySpacesQuery();
  const createFamilySpace = useCreateFamilySpaceMutation();
  const [message, setMessage] = useState("");

  async function create(event: SyntheticEvent<HTMLFormElement>) {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const name = data.get("family_space_name");
    const slug = data.get("family_space_slug");

    if (typeof name !== "string" || typeof slug !== "string") return;

    try {
      await createFamilySpace.mutateAsync({ name, slug });
      form.reset();
      setMessage("Family Space created.");
    } catch {
      setMessage("That Family Space could not be created.");
    }
  }

  return (
    <section aria-labelledby="family-spaces-title">
      <h2 id="family-spaces-title">Your Family Spaces</h2>
      {canCreate && (
        <form onSubmit={(event) => void create(event)}>
          <label htmlFor="family-space-name">Family Space name</label>
          <input id="family-space-name" name="family_space_name" required />
          <label htmlFor="family-space-slug">URL name</label>
          <input
            id="family-space-slug"
            name="family_space_slug"
            pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
            required
          />
          <button type="submit" disabled={createFamilySpace.isPending}>
            Create Family Space
          </button>
        </form>
      )}
      {message !== "" && <p role="status">{message}</p>}
      {familySpaces.isPending && <p role="status">Loading Family Spaces…</p>}
      {familySpaces.isError && (
        <p role="alert">Family Spaces could not be loaded.</p>
      )}
      {familySpaces.isSuccess && familySpaces.data.length === 0 && (
        <p>You do not belong to a Family Space yet.</p>
      )}
      {familySpaces.data?.map((familySpace) => (
        <section
          key={familySpace.id}
          aria-labelledby={`family-${familySpace.id}`}
        >
          <h3 id={`family-${familySpace.id}`}>{familySpace.name}</h3>
          <p>Your role: {familySpace.role}</p>
          {(familySpace.role === "owner" ||
            familySpace.role === "administrator") && (
            <InvitationManagement familySpaceId={familySpace.id} />
          )}
        </section>
      ))}
    </section>
  );
}
