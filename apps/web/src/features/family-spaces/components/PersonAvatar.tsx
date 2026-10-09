import { useState } from "react";

type PersonAvatarProps = {
  name: string;
  initials?: string;
  portraitUrl?: string;
  className?: string;
};

function initialsFor(value: string) {
  return value
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part.charAt(0).toUpperCase())
    .join("");
}

export function PersonAvatar({
  name,
  initials,
  portraitUrl,
  className = "",
}: PersonAvatarProps) {
  const [failedUrl, setFailedUrl] = useState<string | null>(null);
  const visibleUrl =
    portraitUrl && portraitUrl !== failedUrl ? portraitUrl : null;

  return (
    <span
      className={`shell-avatar shell-search-avatar ${className}`.trim()}
      aria-hidden="true"
    >
      {visibleUrl ? (
        <img
          src={visibleUrl}
          alt=""
          onError={() => {
            setFailedUrl(visibleUrl);
          }}
        />
      ) : (
        initials || initialsFor(name)
      )}
    </span>
  );
}
