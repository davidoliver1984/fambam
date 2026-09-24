import { useState } from "react";

import { MediaVariantImage } from "@/features/media-uploads/components/MediaVariantImage";
import type { MediaVariantTransform } from "@/features/media-uploads/types/mediaUpload";

import {
  usePhotoVersionDeliveryQuery,
  usePhotoVersionsQuery,
} from "../hooks/usePhotoEditor";

type Props = {
  familySlug: string;
  photoId: string;
  mediaUploadId: string;
  alt: string;
  className?: string;
  fallbackTransform?: MediaVariantTransform;
};

export function PhotoPresentationImage({
  familySlug,
  photoId,
  mediaUploadId,
  alt,
  className,
  fallbackTransform = "display",
}: Props) {
  const versions = usePhotoVersionsQuery(familySlug, photoId);
  const activeId = versions.data?.active_photo_version_id ?? null;
  const delivery = usePhotoVersionDeliveryQuery(familySlug, photoId, activeId);
  const [failedUrl, setFailedUrl] = useState<string | null>(null);

  if (versions.isPending) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="status">
        Loading photograph…
      </div>
    );
  }
  if (versions.isError) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="alert">
        This photograph is currently unavailable.
      </div>
    );
  }
  if (activeId === null) {
    return (
      <MediaVariantImage
        familySlug={familySlug}
        mediaUploadId={mediaUploadId}
        transform={fallbackTransform}
        alt={alt}
        className={className}
      />
    );
  }
  if (delivery.isPending) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="status">
        Loading edited photograph…
      </div>
    );
  }
  if (delivery.isError || failedUrl === delivery.data.url) {
    return (
      <div className={`${className ?? ""} media-image-state`} role="alert">
        The edited photograph is unavailable.
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
