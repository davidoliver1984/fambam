import type { HTMLAttributes, ReactNode } from "react";

type SurfaceProps = HTMLAttributes<HTMLElement> & {
  children: ReactNode;
  as?: "article" | "section" | "div";
  padded?: boolean;
  quiet?: boolean;
};

export function Surface({
  children,
  as: Element = "div",
  padded = true,
  quiet = false,
  className,
  ...props
}: SurfaceProps) {
  const classes = [
    "ui-surface",
    padded ? "ui-surface--padded" : "",
    quiet ? "ui-surface--quiet" : "",
    className ?? "",
  ]
    .filter(Boolean)
    .join(" ");

  return (
    <Element {...props} className={classes}>
      {children}
    </Element>
  );
}
