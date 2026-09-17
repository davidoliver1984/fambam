import { useState, type ReactNode } from "react";
import { Link } from "react-router";

import heroImage from "../../../assets/hero.png";
import "./UiPlaygroundPage.css";

function Specimen({
  title,
  children,
  wide = false,
}: {
  title: string;
  children: ReactNode;
  wide?: boolean;
}) {
  return (
    <section className={`ui-specimen${wide ? " ui-specimen--wide" : ""}`}>
      <p className="ui-specimen__label">{title}</p>
      <div className="ui-specimen__content">{children}</div>
    </section>
  );
}

const swatches = [
  ["Ink", "#30261f"],
  ["Earth", "#7c3f2a"],
  ["Clay", "#8d4b32"],
  ["Cream", "#f8f3ea"],
  ["Paper", "#fffdf9"],
  ["Line", "#d8cbc2"],
  ["Wash", "#ede5df"],
  ["Success", "#386641"],
] as const;

const photoGridItems = [
  { title: "Summer at the coast", meta: "August 1987", position: "50% 30%" },
  { title: "Nan's birthday", meta: "12 March 1992", position: "25% 50%" },
  { title: "School sports day", meta: "Approx. 1984", position: "75% 45%" },
  { title: "Untitled photograph", meta: "Date unknown", position: "50% 75%" },
  { title: "Christmas morning", meta: "December 1996", position: "20% 20%" },
  { title: "On Blackpool beach", meta: "July 1979", position: "80% 65%" },
] as const;

export function UiPlaygroundPage() {
  const [activeTab, setActiveTab] = useState("Photographs");

  return (
    <main className="ui-playground" aria-labelledby="ui-playground-title">
      <header className="ui-playground__hero">
        <div>
          <p className="eyebrow">Fambam design workshop</p>
          <h1 id="ui-playground-title">Interface elements</h1>
          <p className="ui-playground__intro">
            A living inventory of the visual building blocks used across the
            family archive. Change the styles here, then carry the decisions
            into shared product components.
          </p>
        </div>
        <aside className="ui-playground__notice" aria-label="Development note">
          <strong>Development only</strong>
          <span>This route is not included in production builds.</span>
        </aside>
      </header>

      <nav className="ui-jump-nav" aria-label="Playground sections">
        <a href="#foundations">Foundations</a>
        <a href="#actions">Actions</a>
        <a href="#forms">Forms</a>
        <a href="#feedback">Feedback</a>
        <a href="#content">Content</a>
        <a href="#navigation">Navigation</a>
      </nav>

      <div className="ui-playground__sections">
        <section id="foundations" className="ui-section">
          <SectionHeading number="01" title="Foundations">
            Typography, colour, spacing and inline text treatments.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen title="Display heading" wide>
              <h1 className="ui-display">Every picture holds a story.</h1>
            </Specimen>
            <Specimen title="Heading scale">
              <div className="ui-heading-stack">
                <h1>Heading one</h1>
                <h2>Heading two</h2>
                <h3>Heading three</h3>
                <h4>Heading four</h4>
              </div>
            </Specimen>
            <Specimen title="Body copy">
              <p>
                Fambam keeps photographs, people and the stories around them
                together in one private family space.
              </p>
              <p className="ui-muted">
                Secondary text gives useful context without competing with the
                main story.
              </p>
              <small>Small print and supporting metadata · 11 Sep 2026</small>
            </Specimen>
            <Specimen title="Eyebrow and links">
              <p className="eyebrow">Family archive</p>
              <p>
                <a href="#content">Inline text link</a>
                <br />
                <Link to="/">Internal React Router link</Link>
              </p>
            </Specimen>
            <Specimen title="Colour palette" wide>
              <div className="ui-swatches">
                {swatches.map(([name, colour]) => (
                  <div className="ui-swatch" key={name}>
                    <span style={{ backgroundColor: colour }} />
                    <strong>{name}</strong>
                    <code>{colour}</code>
                  </div>
                ))}
              </div>
            </Specimen>
          </div>
        </section>

        <section id="actions" className="ui-section">
          <SectionHeading number="02" title="Actions">
            Buttons, text actions, badges and grouped controls.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen title="Primary buttons">
              <div className="ui-action-row">
                <button className="ui-button" type="button">
                  Save changes
                </button>
                <button className="ui-button" type="button" disabled>
                  Saving…
                </button>
              </div>
            </Specimen>
            <Specimen title="Secondary buttons">
              <div className="ui-action-row">
                <button
                  className="ui-button ui-button--secondary"
                  type="button"
                >
                  Cancel
                </button>
                <button className="ui-button ui-button--quiet" type="button">
                  Skip for now
                </button>
              </div>
            </Specimen>
            <Specimen title="Destructive action">
              <button className="ui-button ui-button--danger" type="button">
                Remove from album
              </button>
              <p className="ui-help">
                Use only when the effect is reversible or clearly explained.
              </p>
            </Specimen>
            <Specimen title="Status badges">
              <div className="ui-action-row">
                <span className="ui-badge ui-badge--success">Ready</span>
                <span className="ui-badge ui-badge--warning">Processing</span>
                <span className="ui-badge ui-badge--neutral">Draft</span>
                <span className="ui-badge ui-badge--danger">Failed</span>
              </div>
            </Specimen>
          </div>
        </section>

        <section id="forms" className="ui-section">
          <SectionHeading number="03" title="Forms">
            Inputs and selection controls in their common states.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen title="Text input">
              <div className="ui-field">
                <label htmlFor="ui-photo-title">Photo title</label>
                <input
                  aria-describedby="ui-photo-title-help"
                  defaultValue="Summer at the coast"
                  id="ui-photo-title"
                  type="text"
                />
                <small id="ui-photo-title-help">
                  Use the name your family would recognise.
                </small>
              </div>
            </Specimen>
            <Specimen title="Search input">
              <label className="ui-field">
                <span>Search the archive</span>
                <input placeholder="People, places or stories…" type="search" />
              </label>
            </Specimen>
            <Specimen title="Email and password">
              <div className="ui-field-stack">
                <label className="ui-field">
                  <span>Email address</span>
                  <input placeholder="name@example.com" type="email" />
                </label>
                <label className="ui-field">
                  <span>Password</span>
                  <input defaultValue="example-password" type="password" />
                </label>
              </div>
            </Specimen>
            <Specimen title="Date and select">
              <div className="ui-field-stack">
                <label className="ui-field">
                  <span>Date taken</span>
                  <input type="date" />
                </label>
                <label className="ui-field">
                  <span>Visibility</span>
                  <select defaultValue="family">
                    <option value="family">Family Space</option>
                    <option value="selected">Selected people</option>
                    <option value="private">Private</option>
                  </select>
                </label>
              </div>
            </Specimen>
            <Specimen title="Textarea" wide>
              <label className="ui-field">
                <span>Tell the story behind this photograph</span>
                <textarea
                  defaultValue="We took this just before the rain arrived."
                  rows={4}
                />
                <small>Stories can be edited later.</small>
              </label>
            </Specimen>
            <Specimen title="Checkboxes">
              <fieldset className="ui-fieldset">
                <legend>Include in search</legend>
                <label className="ui-choice">
                  <input defaultChecked type="checkbox" /> Photographs
                </label>
                <label className="ui-choice">
                  <input defaultChecked type="checkbox" /> People
                </label>
                <label className="ui-choice">
                  <input type="checkbox" /> Stories
                </label>
              </fieldset>
            </Specimen>
            <Specimen title="Radio buttons">
              <fieldset className="ui-fieldset">
                <legend>Duplicate decision</legend>
                <label className="ui-choice">
                  <input defaultChecked name="duplicate" type="radio" /> Use
                  existing Photo
                </label>
                <label className="ui-choice">
                  <input name="duplicate" type="radio" /> Create a new Photo
                </label>
                <label className="ui-choice">
                  <input name="duplicate" type="radio" /> Cancel
                </label>
              </fieldset>
            </Specimen>
            <Specimen title="File upload">
              <label className="ui-dropzone">
                <strong>Add photographs</strong>
                <span>Choose files or drag them here</span>
                <input multiple type="file" />
              </label>
            </Specimen>
            <Specimen title="Validation states">
              <div className="ui-field-stack">
                <label className="ui-field ui-field--success">
                  <span>Preferred name</span>
                  <input defaultValue="Maya Mercer" type="text" />
                  <small>Name is available.</small>
                </label>
                <label className="ui-field ui-field--error">
                  <span>Relative&apos;s email address</span>
                  <input
                    aria-invalid="true"
                    defaultValue="not-an-email"
                    type="email"
                  />
                  <small role="alert">Enter a valid email address.</small>
                </label>
                <label className="ui-field">
                  <span>Unavailable field</span>
                  <input
                    disabled
                    defaultValue="Managed by the archive"
                    type="text"
                  />
                </label>
              </div>
            </Specimen>
          </div>
        </section>

        <section id="feedback" className="ui-section">
          <SectionHeading number="04" title="Feedback and state">
            Messages for success, information, warnings, errors and waiting.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen title="Information message">
              <Message kind="info" title="About family visibility">
                Only invited members of this Family Space can see this Photo.
              </Message>
            </Specimen>
            <Specimen title="Success message">
              <Message kind="success" title="Changes saved">
                Your family archive has been updated.
              </Message>
            </Specimen>
            <Specimen title="Warning message">
              <Message kind="warning" title="Possible duplicate">
                A similar photograph is already in this Family Space.
              </Message>
            </Specimen>
            <Specimen title="Error message">
              <Message kind="error" title="Photograph could not be loaded">
                Try again. Storage details remain private.
              </Message>
            </Specimen>
            <Specimen title="Loading state">
              <div className="ui-loading" role="status">
                <span className="ui-spinner" aria-hidden="true" />
                <span>Gathering family memories…</span>
              </div>
            </Specimen>
            <Specimen title="Empty state">
              <div className="ui-empty">
                <span aria-hidden="true">◇</span>
                <h3>No photographs yet</h3>
                <p>Add the first picture to begin this family album.</p>
                <button
                  className="ui-button ui-button--secondary"
                  type="button"
                >
                  Add photographs
                </button>
              </div>
            </Specimen>
          </div>
        </section>

        <section id="content" className="ui-section">
          <SectionHeading number="05" title="Content patterns">
            Cards, photographs, metadata, activity and tabular records.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen
              title="Photo grid · Search results / Albums / Photos"
              wide
            >
              <div className="ui-photo-grid" aria-label="Photograph results">
                {photoGridItems.map((photo, index) => (
                  <article
                    className={`ui-photo-grid__card${
                      index === 1 ? " is-selected" : ""
                    }`}
                    key={photo.title}
                  >
                    <a href="#content" aria-label={`Open ${photo.title}`}>
                      <div className="ui-photo-grid__image">
                        <img
                          src={heroImage}
                          alt="A sample family archive illustration"
                          style={{ objectPosition: photo.position }}
                        />
                        {index === 1 && (
                          <span className="ui-photo-grid__selection">
                            Selected
                          </span>
                        )}
                        {index === 4 && (
                          <span className="ui-photo-grid__privacy">
                            Private
                          </span>
                        )}
                      </div>
                      <div className="ui-photo-grid__caption">
                        <h3>{photo.title}</h3>
                        <p>{photo.meta}</p>
                      </div>
                    </a>
                  </article>
                ))}
              </div>
            </Specimen>
            <Specimen title="Photograph card">
              <article className="ui-photo-card">
                <img
                  src={heroImage}
                  alt="A sample family archive illustration"
                />
                <div>
                  <p className="eyebrow">August 1987</p>
                  <h3>At the summer fair</h3>
                  <p>Blackpool, Lancashire</p>
                </div>
              </article>
            </Specimen>
            <Specimen title="Person card">
              <article className="ui-person-card">
                <span className="ui-avatar" aria-hidden="true">
                  MM
                </span>
                <div>
                  <h3>Maya Mercer</h3>
                  <p>1946–2021 · 28 photographs</p>
                </div>
                <a href="#foundations">View person</a>
              </article>
            </Specimen>
            <Specimen title="Album card">
              <article className="ui-album-card">
                <div className="ui-album-card__stack" aria-hidden="true">
                  <img src={heroImage} alt="" />
                </div>
                <p className="eyebrow">12 photographs</p>
                <h3>Family holidays</h3>
                <p>Moments gathered from 1982 to 1996.</p>
              </article>
            </Specimen>
            <Specimen title="Notification item">
              <article className="ui-notification">
                <span className="ui-notification__dot" aria-label="Unread" />
                <div>
                  <p>
                    <strong>Jamie added 6 photographs</strong> to Family
                    holidays.
                  </p>
                  <small>12 minutes ago</small>
                </div>
                <button className="ui-button ui-button--quiet" type="button">
                  View
                </button>
              </article>
            </Specimen>
            <Specimen title="Activity list" wide>
              <ol className="ui-timeline">
                <li>
                  <span>11 Sep</span>
                  <p>
                    <strong>A new Story was added</strong>
                    <br />
                    “That was Dad&apos;s old camera…”
                  </p>
                </li>
                <li>
                  <span>09 Sep</span>
                  <p>
                    <strong>3 people were identified</strong>
                    <br />
                    In Summer at the coast.
                  </p>
                </li>
                <li>
                  <span>02 Sep</span>
                  <p>
                    <strong>An Album was created</strong>
                    <br />
                    School days, 1974–1981.
                  </p>
                </li>
              </ol>
            </Specimen>
            <Specimen title="Metadata definition list">
              <dl className="ui-metadata">
                <div>
                  <dt>Date taken</dt>
                  <dd>Approx. summer 1987</dd>
                </div>
                <div>
                  <dt>Added by</dt>
                  <dd>Jamie Mercer</dd>
                </div>
                <div>
                  <dt>Visibility</dt>
                  <dd>Family Space</dd>
                </div>
                <div>
                  <dt>People</dt>
                  <dd>Maya, David and Rose</dd>
                </div>
              </dl>
            </Specimen>
            <Specimen title="Data table" wide>
              <div className="ui-table-wrap">
                <table className="ui-table">
                  <thead>
                    <tr>
                      <th scope="col">Export</th>
                      <th scope="col">Requested</th>
                      <th scope="col">Status</th>
                      <th scope="col">
                        <span className="ui-visually-hidden">Action</span>
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td>Family archive</td>
                      <td>11 Sep 2026</td>
                      <td>
                        <span className="ui-badge ui-badge--success">
                          Ready
                        </span>
                      </td>
                      <td>
                        <a href="#content">Download</a>
                      </td>
                    </tr>
                    <tr>
                      <td>Personal archive</td>
                      <td>09 Sep 2026</td>
                      <td>
                        <span className="ui-badge ui-badge--warning">
                          Processing
                        </span>
                      </td>
                      <td>
                        <span className="ui-muted">Waiting</span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </Specimen>
            <Specimen title="Disclosure panel" wide>
              <details className="ui-details">
                <summary>Notification preferences</summary>
                <p>Choose which family updates should reach your inbox.</p>
                <label className="ui-choice">
                  <input defaultChecked type="checkbox" /> New photographs
                </label>
                <label className="ui-choice">
                  <input type="checkbox" /> New comments
                </label>
              </details>
            </Specimen>
          </div>
        </section>

        <section id="navigation" className="ui-section">
          <SectionHeading number="06" title="Navigation">
            Wayfinding patterns for moving around the archive.
          </SectionHeading>
          <div className="ui-specimen-grid">
            <Specimen title="Breadcrumbs" wide>
              <nav className="ui-breadcrumbs" aria-label="Breadcrumb">
                <a href="#navigation">Mercer Family</a>
                <span aria-hidden="true">/</span>
                <a href="#navigation">Albums</a>
                <span aria-hidden="true">/</span>
                <span aria-current="page">Family holidays</span>
              </nav>
            </Specimen>
            <Specimen title="Tabs" wide>
              <div
                className="ui-tabs"
                role="tablist"
                aria-label="Archive sections"
              >
                {["Photographs", "Stories", "People"].map((tab) => (
                  <button
                    aria-selected={activeTab === tab}
                    className={activeTab === tab ? "is-active" : ""}
                    key={tab}
                    onClick={() => {
                      setActiveTab(tab);
                    }}
                    role="tab"
                    type="button"
                  >
                    {tab}
                  </button>
                ))}
              </div>
              <div className="ui-tab-panel" role="tabpanel">
                Showing the <strong>{activeTab.toLowerCase()}</strong> view.
              </div>
            </Specimen>
            <Specimen title="Pagination">
              <nav className="ui-pagination" aria-label="Pagination">
                <button
                  className="ui-button ui-button--secondary"
                  type="button"
                >
                  ← Previous
                </button>
                <span>Page 2 of 8</span>
                <button
                  className="ui-button ui-button--secondary"
                  type="button"
                >
                  Next →
                </button>
              </nav>
            </Specimen>
            <Specimen title="Inline action menu">
              <div className="ui-action-menu">
                <a href="#navigation">Edit details</a>
                <a href="#navigation">Move to Album</a>
                <button type="button">Remove</button>
              </div>
            </Specimen>
          </div>
        </section>
      </div>
    </main>
  );
}

function SectionHeading({
  number,
  title,
  children,
}: {
  number: string;
  title: string;
  children: ReactNode;
}) {
  return (
    <div className="ui-section__heading">
      <p className="ui-section__number">{number}</p>
      <div>
        <h2>{title}</h2>
        <p>{children}</p>
      </div>
    </div>
  );
}

function Message({
  kind,
  title,
  children,
}: {
  kind: "info" | "success" | "warning" | "error";
  title: string;
  children: ReactNode;
}) {
  return (
    <div
      className={`ui-message ui-message--${kind}`}
      role={kind === "error" ? "alert" : "status"}
    >
      <strong>{title}</strong>
      <span>{children}</span>
    </div>
  );
}
