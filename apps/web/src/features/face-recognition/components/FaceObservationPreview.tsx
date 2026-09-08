import type { CSSProperties } from "react";

import type { FaceObservationSummary } from "../types/faceRecognition";

type Props = {
  observation: FaceObservationSummary;
};

export function FaceObservationPreview({ observation }: Props) {
  const imageWidth = observation.image_width;
  const imageHeight = observation.image_height;
  const hasDimensions =
    imageWidth !== null &&
    imageWidth > 0 &&
    imageHeight !== null &&
    imageHeight > 0;
  const faceRegion: CSSProperties | null = hasDimensions
    ? {
        left: `${((observation.bounds.x / imageWidth) * 100).toString()}%`,
        top: `${((observation.bounds.y / imageHeight) * 100).toString()}%`,
        width: `${((observation.bounds.width / imageWidth) * 100).toString()}%`,
        height: `${((observation.bounds.height / imageHeight) * 100).toString()}%`,
      }
    : null;
  const regionLabel = hasDimensions
    ? `${Math.round((observation.bounds.width / imageWidth) * 100).toString()}% × ${Math.round((observation.bounds.height / imageHeight) * 100).toString()}%`
    : `index ${(observation.face_index + 1).toString()}`;

  return (
    <figure className="face-observation-preview">
      <div className="face-observation-image">
        <img
          src={observation.image_url}
          alt={`Photograph containing detected face ${(observation.face_index + 1).toString()}`}
        />
        {faceRegion ? <span aria-hidden="true" style={faceRegion} /> : null}
      </div>
      <figcaption>
        Detected face {observation.face_index + 1} · region {regionLabel}
      </figcaption>
    </figure>
  );
}
