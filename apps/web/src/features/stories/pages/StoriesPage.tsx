import { Link, useParams } from "react-router";

export function StoriesPage() {
  const { familySlug = "" } = useParams();
  const base = `/families/${encodeURIComponent(familySlug)}`;

  return (
    <main className="journey-page" aria-labelledby="stories-title">
      <p className="eyebrow">Family archive</p>
      <h1 id="stories-title">Stories</h1>
      <p>Record the memories behind a Person, photograph, Album or Event.</p>
      <p>
        <Link className="journey-action" to={`${base}/stories/new`}>
          Create a Story
        </Link>
      </p>
      <p>
        <Link to={`${base}/search`}>Find Stories in the archive</Link>
      </p>
      <p>
        Recent Stories also appear on the family homepage alongside the people
        and events they describe.
      </p>
      <Link to={base}>Back to Family Space</Link>
    </main>
  );
}
