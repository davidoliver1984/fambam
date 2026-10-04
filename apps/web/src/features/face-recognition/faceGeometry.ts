import type { CSSProperties } from "react";

import type { FaceBounds } from "./types/faceRecognition";

export function faceBoundsStyle(
  bounds: FaceBounds,
  imageWidth: number,
  imageHeight: number,
): CSSProperties {
  return {
    left: `${String((bounds.x / imageWidth) * 100)}%`,
    top: `${String((bounds.y / imageHeight) * 100)}%`,
    width: `${String((bounds.width / imageWidth) * 100)}%`,
    height: `${String((bounds.height / imageHeight) * 100)}%`,
  };
}
