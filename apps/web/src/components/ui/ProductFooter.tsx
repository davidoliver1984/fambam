import { Link } from "react-router";

import { ShellIcon } from "@/features/family-spaces/components/ShellIcon";

type ProductFooterProps = {
  familyHome?: string;
};

const groups = [
  {
    title: "About",
    links: ["About Fambam", "How it works", "Our story", "Contact"],
  },
  {
    title: "Legal",
    links: ["Terms of Service", "Privacy Policy", "Cookies", "Data & Security"],
  },
  {
    title: "Support",
    links: ["Help Centre", "Get in touch", "Feature requests", "Status"],
  },
];

function SocialIcon({ kind }: { kind: "x" | "facebook" | "instagram" }) {
  if (kind === "x") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M5 4l14 16M19 4 5 20" />
      </svg>
    );
  }
  if (kind === "facebook") {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true">
        <path d="M14 8h4V4h-4c-3 0-5 2-5 5v3H6v4h3v5h4v-5h4l1-4h-5V9c0-.7.3-1 1-1Z" />
      </svg>
    );
  }
  return (
    <svg viewBox="0 0 24 24" aria-hidden="true">
      <rect x="3.5" y="3.5" width="17" height="17" rx="5" />
      <circle cx="12" cy="12" r="4" />
      <circle cx="17.5" cy="6.8" r=".8" fill="currentColor" stroke="none" />
    </svg>
  );
}

export function ProductFooter({ familyHome = "/" }: ProductFooterProps) {
  return (
    <footer className="shell-global-footer">
      <div className="shell-page-width shell-footer-main">
        <div className="shell-footer-brand-block">
          <Link className="shell-footer-brand" to={familyHome}>
            Fambam <ShellIcon name="heart" fill="currentColor" />
          </Link>
          <p>A private place for family photographs, stories and memories.</p>
        </div>
        <nav className="shell-footer-links" aria-label="Footer navigation">
          {groups.map((group) => (
            <div key={group.title}>
              <b>{group.title}</b>
              {group.links.map((label) => (
                <button type="button" key={label}>
                  {label}
                </button>
              ))}
            </div>
          ))}
        </nav>
      </div>
      <div className="shell-footer-bar">
        <div className="shell-page-width">
          <span>© 2026 Fambam. Family memories, carefully kept.</span>
          <nav className="shell-footer-social" aria-label="Social media">
            <button type="button" aria-label="X / Twitter">
              <SocialIcon kind="x" />
            </button>
            <button type="button" aria-label="Facebook">
              <SocialIcon kind="facebook" />
            </button>
            <button type="button" aria-label="Instagram">
              <SocialIcon kind="instagram" />
            </button>
          </nav>
        </div>
      </div>
    </footer>
  );
}
