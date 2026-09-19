import { forwardRef, type ButtonHTMLAttributes } from "react";
import { Link, type LinkProps } from "react-router";

export type ButtonVariant = "primary" | "secondary" | "ghost" | "danger";

function buttonClassName(
  variant: ButtonVariant,
  iconOnly: boolean,
  className?: string,
) {
  return [
    "ui-button",
    `ui-button--${variant}`,
    iconOnly ? "ui-button--icon" : "",
    className ?? "",
  ]
    .filter(Boolean)
    .join(" ");
}

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: ButtonVariant;
  iconOnly?: boolean;
};

export const Button = forwardRef<HTMLButtonElement, ButtonProps>(
  function Button(
    {
      variant = "secondary",
      iconOnly = false,
      className,
      type = "button",
      ...props
    },
    ref,
  ) {
    return (
      <button
        {...props}
        ref={ref}
        type={type}
        className={buttonClassName(variant, iconOnly, className)}
      />
    );
  },
);

type ButtonLinkProps = LinkProps & {
  variant?: ButtonVariant;
  iconOnly?: boolean;
};

export function ButtonLink({
  variant = "secondary",
  iconOnly = false,
  className,
  ...props
}: ButtonLinkProps) {
  return (
    <Link
      {...props}
      className={buttonClassName(variant, iconOnly, className)}
    />
  );
}
