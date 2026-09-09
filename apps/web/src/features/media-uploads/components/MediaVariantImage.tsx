import { useState } from "react";

import { useMediaVariantQuery } from "../hooks/useMediaVariantQuery";
import type { MediaVariantTransform } from "../types/mediaUpload";

type Props = {
  familySlug: string;
  mediaUploadId: string;
  transform: MediaVariantTransform;
  alt: string;
  className?: string;
};

export function MediaVariantImage({
  familySlug,
  mediaUploadId,
  transform,
  alt,
  className,
}: Props) {
  const delivery = useMediaVariantQuery(familySlug, mediaUploadId, transform);
  const [failedUrl, setFailedUrl] = useState<string | null>(null);

  if (delivery.isPending) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="status">
        Loading photograph…
      </div>
    );
  }

  if (delivery.isError || failedUrl === delivery.data.url) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="alert">
        This photograph is currently unavailable.
      </div>
    );
  }

  return (
    <img
      className={className}
      src={delivery.data.url}
      alt={alt}
      onError={() => {
        setFailedUrl(delivery.data.url);
      }}
    />
  );
}
