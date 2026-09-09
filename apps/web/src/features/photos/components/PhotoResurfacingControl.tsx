import { useSetPhotoResurfacingMutation } from "@/features/photos/hooks/usePhotoMutations";

type Props = {
  familySlug: string;
  photoId: string;
  excluded: boolean;
};

export function PhotoResurfacingControl({
  familySlug,
  photoId,
  excluded,
}: Props) {
  const update = useSetPhotoResurfacingMutation(familySlug, photoId);

  return (
    <section aria-labelledby="photo-resurfacing-title">
      <h2 id="photo-resurfacing-title">Memory resurfacing</h2>
      <p>
        {excluded
          ? "This Photo stays in the archive but will not appear automatically in memories."
          : "This Photo may appear automatically in date and person-centred memories."}
      </p>
      <button
        type="button"
        aria-pressed={excluded}
        disabled={update.isPending}
        onClick={() => {
          update.mutate(!excluded);
        }}
      >
        {update.isPending
          ? "Saving…"
          : excluded
            ? "Allow in memories"
            : "Exclude from memories"}
      </button>
      {update.isError && (
        <p role="alert">The memory setting could not be saved.</p>
      )}
      {update.isSuccess && (
        <p role="status">The memory setting has been saved.</p>
      )}
    </section>
  );
}
