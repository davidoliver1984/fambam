import { Link } from "react-router";

type ProductFooterProps = {
  familyHome?: string;
};

export function ProductFooter({ familyHome }: ProductFooterProps) {
  return (
    <footer className="ui-product-footer">
      <div className="ui-product-footer__grid">
        <div>
          <strong className="ui-product-footer__brand">Fambam</strong>
          <p>A private place for family photographs, stories and memories.</p>
        </div>
        <nav aria-label="Explore Fambam">
          <h2>Explore</h2>
          <ul>
            {familyHome && (
              <li>
                <Link to={familyHome}>Family home</Link>
              </li>
            )}
            <li>
              <Link to="/account">Your account</Link>
            </li>
          </ul>
        </nav>
        <section aria-labelledby="fambam-support-heading">
          <h2 id="fambam-support-heading">Support</h2>
          <ul>
            <li>Ask your family administrator for help</li>
          </ul>
        </section>
        <section aria-labelledby="fambam-privacy-heading">
          <h2 id="fambam-privacy-heading">Privacy</h2>
          <ul>
            <li>Invite-only</li>
            <li>Family-controlled access</li>
          </ul>
        </section>
      </div>
      <div className="ui-product-footer__legal">
        <span>Family memories, carefully kept.</span>
        <span>Private by design.</span>
      </div>
    </footer>
  );
}
