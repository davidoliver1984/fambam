"""Local-only face benchmark preparation, annotation, and validation helper."""

from __future__ import annotations

import argparse
import hashlib
import json
import re
import secrets
import shutil
from collections import Counter, defaultdict
from collections.abc import Sequence
from pathlib import Path
from typing import Any, Literal

import cv2
import numpy as np
import uvicorn
from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import FileResponse, HTMLResponse
from pydantic import BaseModel, ConfigDict, Field, model_validator

from app.face_analysis.contracts import DetectedFace
from app.face_analysis.insightface_provider import (
    InsightFaceProvider,
    InsightFaceSettings,
)

IDENTITY_PATTERN = re.compile(r"^person-[a-z0-9]+(?:-[a-z0-9]+)*$")
BENCHMARK_ID_PATTERN = re.compile(r"^B[0-9]{3,}$")
AGE_BANDS = {
    "unknown",
    "child",
    "teen",
    "adult-20s-30s",
    "adult-40s-50s",
    "adult-60-plus",
}
NOTE_FLAGS = {
    "old-scan",
    "damaged-scan",
    "glasses",
    "facial-hair",
    "hairstyle-change",
    "sibling-resemblance",
    "parent-child-resemblance",
    "poor-quality",
    "extreme-profile",
    "occlusion",
}
SUPPORTED_IMAGE_SUFFIXES = {".jpg", ".jpeg", ".png", ".webp", ".tif", ".tiff"}


class AssignmentInput(BaseModel):
    """One human decision for one stable benchmark face index."""

    model_config = ConfigDict(extra="forbid", strict=True)

    face_index: int = Field(ge=0)
    status: Literal["labelled", "unknown", "skipped", "unlabelled"]
    identity_id: str | None = Field(default=None, max_length=80)
    age_band: str = Field(default="unknown", max_length=40)
    note_flags: list[str] = Field(default_factory=list, max_length=16)
    private_note: str = Field(default="", max_length=500)

    @model_validator(mode="after")
    def validate_decision(self) -> AssignmentInput:
        if self.status == "labelled":
            if (
                self.identity_id is None
                or IDENTITY_PATTERN.fullmatch(self.identity_id) is None
            ):
                raise ValueError("labelled faces require an anonymous person-* ID")
        elif self.identity_id is not None:
            raise ValueError("only labelled faces may carry an identity ID")
        if self.age_band not in AGE_BANDS:
            raise ValueError("unsupported age band")
        if len(self.note_flags) != len(set(self.note_flags)) or any(
            value not in NOTE_FLAGS for value in self.note_flags
        ):
            raise ValueError("unsupported or repeated benchmark note flag")
        return self


class ImageAssignmentsInput(BaseModel):
    model_config = ConfigDict(extra="forbid", strict=True)

    assignments: list[AssignmentInput] = Field(max_length=256)


class BenchmarkAnnotationStore:
    """Own private JSON persistence without any application-data dependency."""

    def __init__(self, benchmark_root: Path) -> None:
        self.root = benchmark_root.resolve()
        self.assets = (self.root / "assets").resolve()
        self.annotations_path = self.root / "annotations.json"
        self.manifest_path = self.root / "manifest.json"
        if not self.annotations_path.is_file() or not self.manifest_path.is_file():
            raise ValueError(
                "benchmark root must contain annotations.json and manifest.json"
            )
        if not self.assets.is_dir():
            raise ValueError("benchmark root must contain an assets directory")

    def annotations(self) -> dict[str, dict[str, Any]]:
        raw = _read_json_object(self.annotations_path)
        return {
            str(key): _object(value, f"annotation {key}") for key, value in raw.items()
        }

    def manifest(self) -> dict[str, Any]:
        return _read_json_object(self.manifest_path)

    def benchmark_ids(self) -> list[str]:
        return sorted(self.annotations())

    def asset(self, benchmark_id: str) -> Path:
        if BENCHMARK_ID_PATTERN.fullmatch(benchmark_id) is None:
            raise KeyError(benchmark_id)
        matches = sorted(self.assets.glob(f"{benchmark_id}.*"))
        if len(matches) != 1 or not matches[0].is_file():
            raise ValueError(f"expected exactly one asset for {benchmark_id}")
        resolved = matches[0].resolve()
        if self.assets not in resolved.parents:
            raise ValueError("benchmark asset escaped the private assets directory")
        return resolved

    def image_payload(self, benchmark_id: str) -> dict[str, Any]:
        annotations = self.annotations()
        if benchmark_id not in annotations:
            raise KeyError(benchmark_id)
        annotation = annotations[benchmark_id]
        regions = _regions(annotation, benchmark_id)
        assignments = _normalised_assignments(annotation, regions)
        return {
            "benchmark_id": benchmark_id,
            "asset_url": f"/assets/{benchmark_id}",
            "image_width": int(annotation.get("image_width", 0)),
            "image_height": int(annotation.get("image_height", 0)),
            "regions": regions,
            "assignments": assignments,
            "categories": annotation.get("categories", []),
            "difficulty_notes": annotation.get("difficulty_notes", ""),
            "identities": self.identities(annotations),
        }

    def state_payload(self) -> dict[str, Any]:
        annotations = self.annotations()
        images: list[dict[str, Any]] = []
        for benchmark_id in sorted(annotations):
            annotation = annotations[benchmark_id]
            regions = _regions(annotation, benchmark_id)
            assignments = _normalised_assignments(annotation, regions)
            counter = Counter(str(item["status"]) for item in assignments)
            images.append(
                {
                    "benchmark_id": benchmark_id,
                    "face_count": len(regions),
                    "decided_count": len(regions) - counter["unlabelled"],
                    "complete": counter["unlabelled"] == 0,
                }
            )
        return {
            "images": images,
            "summary": summarize_annotations(annotations),
            "identities": self.identities(annotations),
        }

    def identities(
        self, annotations: dict[str, dict[str, Any]] | None = None
    ) -> list[str]:
        source = annotations if annotations is not None else self.annotations()
        return sorted(
            {
                str(item["identity_id"])
                for annotation in source.values()
                for item in annotation.get("anonymous_identity_groups", [])
                if isinstance(item, dict)
                and item.get("status") == "labelled"
                and isinstance(item.get("identity_id"), str)
            }
        )

    def save_assignments(
        self, benchmark_id: str, submitted: Sequence[AssignmentInput]
    ) -> dict[str, Any]:
        annotations = self.annotations()
        if benchmark_id not in annotations:
            raise KeyError(benchmark_id)
        regions = _regions(annotations[benchmark_id], benchmark_id)
        expected = list(range(len(regions)))
        received = sorted(item.face_index for item in submitted)
        if received != expected or len(received) != len(set(received)):
            raise ValueError("assignments must cover every face index exactly once")

        serialised = [
            {
                "face_index": item.face_index,
                "status": item.status,
                "identity_id": item.identity_id,
                "age_band": item.age_band,
                "note_flags": sorted(item.note_flags),
                "private_note": item.private_note.strip(),
            }
            for item in sorted(submitted, key=lambda value: value.face_index)
        ]
        annotations[benchmark_id]["anonymous_identity_groups"] = serialised
        manifest = self.manifest()
        images = manifest.get("images")
        if not isinstance(images, list):
            raise ValueError("manifest images must be a list")
        matched = False
        for image in images:
            if isinstance(image, dict) and image.get("benchmark_id") == benchmark_id:
                image["anonymous_identity_groups"] = serialised
                matched = True
                break
        if not matched:
            raise ValueError(f"manifest has no image entry for {benchmark_id}")

        self._write_private_json(self.manifest_path, manifest)
        self._write_private_json(self.annotations_path, annotations)
        return self.image_payload(benchmark_id)

    def import_assets(self, incoming: Path) -> dict[str, Any]:
        """Copy unique local images into the private benchmark as auxiliaries."""
        source_root = incoming.resolve()
        if not source_root.is_dir():
            raise ValueError("incoming path must be a directory")

        annotations = self.annotations()
        manifest = self.manifest()
        images = manifest.get("images")
        if not isinstance(images, list):
            raise ValueError("manifest images must be a list")
        known_hashes = {
            _sha256(self.asset(benchmark_id)) for benchmark_id in annotations
        }
        next_number = max(int(value[1:]) for value in annotations) + 1
        imported: list[str] = []
        duplicates: list[str] = []
        unreadable: list[str] = []

        for source in sorted(source_root.iterdir(), key=lambda value: value.name):
            if (
                not source.is_file()
                or source.suffix.lower() not in SUPPORTED_IMAGE_SUFFIXES
            ):
                continue
            try:
                _image_dimensions(source)
            except ValueError:
                unreadable.append(source.name)
                continue
            checksum = _sha256(source)
            if checksum in known_hashes:
                duplicates.append(source.name)
                continue

            benchmark_id = f"B{next_number:03d}"
            next_number += 1
            target = self.assets / f"{benchmark_id}{source.suffix.lower()}"
            shutil.copy2(source, target)
            annotation: dict[str, Any] = {
                "expected_face_count": 0,
                "expected_face_count_source": "local-detector-provisional",
                "core": False,
                "categories": ["recognition-calibration"],
                "difficulty_notes": (
                    "Private auxiliary recognition-calibration image; detected "
                    "faces require human identity annotation."
                ),
                "scene_group": f"recognition-{benchmark_id.lower()}",
            }
            annotations[benchmark_id] = annotation
            images.append(
                {
                    "benchmark_id": benchmark_id,
                    **annotation,
                    "private_path": str(target.resolve()),
                    "source_filename": source.name,
                    "source_path": str(source.resolve()),
                }
            )
            known_hashes.add(checksum)
            imported.append(benchmark_id)

        self._sync_manifest_value(manifest, annotations)
        self._write_private_json(self.manifest_path, manifest)
        self._write_private_json(self.annotations_path, annotations)
        return {
            "imported": imported,
            "exact_duplicates_skipped": duplicates,
            "unreadable_skipped": unreadable,
        }

    def prepare_regions(
        self,
        provider: InsightFaceProvider,
        *,
        replace_unassigned: bool = False,
    ) -> int:
        annotations = self.annotations()
        prepared = 0
        for benchmark_id in sorted(annotations):
            annotation = annotations[benchmark_id]
            asset = self.asset(benchmark_id)
            checksum = _sha256(asset)
            existing = annotation.get("face_regions")
            if isinstance(existing, list):
                source = annotation.get("face_region_source")
                if isinstance(source, dict) and source.get("asset_sha256") == checksum:
                    continue
                assignments = annotation.get("anonymous_identity_groups", [])
                has_decision = any(
                    isinstance(item, dict)
                    and item.get("status") not in {None, "unlabelled"}
                    for item in assignments
                )
                if has_decision or not replace_unassigned:
                    raise ValueError(
                        f"{benchmark_id} regions changed; refusing to replace "
                        "stable indices"
                    )

            width, height = _image_dimensions(asset)
            faces = provider.analyze(asset)
            annotation["image_width"] = width
            annotation["image_height"] = height
            annotation["face_regions"] = region_records(faces)
            annotation["face_region_source"] = {
                "asset_sha256": checksum,
                "provider": provider.identity.provider,
                "model_identifier": provider.identity.model_identifier,
                "model_weight_checksum": provider.identity.model_weight_checksum,
                "config_hash": provider.identity.config_hash,
            }
            annotation["anonymous_identity_groups"] = [
                _empty_assignment(index) for index in range(len(faces))
            ]
            if annotation.get("expected_face_count_source") == (
                "local-detector-provisional"
            ):
                annotation["expected_face_count"] = len(faces)
            prepared += 1

        self._write_private_json(self.annotations_path, annotations)
        self._mirror_all_assignments(annotations)
        return prepared

    def validate(self, *, require_complete: bool = False) -> list[str]:
        errors: list[str] = []
        try:
            annotations = self.annotations()
            manifest = self.manifest()
        except (OSError, ValueError, json.JSONDecodeError) as exception:
            return [str(exception)]
        images = manifest.get("images")
        manifest_images = (
            {
                str(item.get("benchmark_id")): item
                for item in images
                if isinstance(images, list) and isinstance(item, dict)
            }
            if isinstance(images, list)
            else {}
        )
        for benchmark_id, annotation in sorted(annotations.items()):
            try:
                self.asset(benchmark_id)
                regions = _regions(annotation, benchmark_id)
                assignments = _normalised_assignments(annotation, regions)
            except (KeyError, ValueError) as exception:
                errors.append(str(exception))
                continue
            indices = [int(item["face_index"]) for item in assignments]
            if indices != list(range(len(regions))) or len(indices) != len(
                set(indices)
            ):
                errors.append(f"{benchmark_id}: face assignments are not one-per-index")
            for item in assignments:
                try:
                    AssignmentInput.model_validate(item)
                except ValueError as exception:
                    errors.append(
                        f"{benchmark_id} face {item.get('face_index')}: {exception}"
                    )
                if require_complete and item["status"] == "unlabelled":
                    errors.append(
                        f"{benchmark_id} face {item['face_index']}: unlabelled"
                    )
            manifest_image = manifest_images.get(benchmark_id)
            if manifest_image is None:
                errors.append(f"{benchmark_id}: missing from manifest")
            elif manifest_image.get("anonymous_identity_groups") != assignments:
                errors.append(f"{benchmark_id}: manifest assignment mirror has drifted")
        return errors

    def _mirror_all_assignments(self, annotations: dict[str, dict[str, Any]]) -> None:
        manifest = self.manifest()
        self._sync_manifest_value(manifest, annotations)
        self._write_private_json(self.manifest_path, manifest)

    def _sync_manifest_value(
        self, manifest: dict[str, Any], annotations: dict[str, dict[str, Any]]
    ) -> None:
        images = manifest.get("images")
        if not isinstance(images, list):
            raise ValueError("manifest images must be a list")
        for image in images:
            if not isinstance(image, dict):
                continue
            benchmark_id = image.get("benchmark_id")
            if isinstance(benchmark_id, str) and benchmark_id in annotations:
                annotation = annotations[benchmark_id]
                image["anonymous_identity_groups"] = annotation.get(
                    "anonymous_identity_groups", []
                )
                image["expected_face_count"] = annotation.get("expected_face_count", 0)
        manifest["image_count"] = len(annotations)
        manifest["core_image_count"] = sum(
            bool(annotation.get("core")) for annotation in annotations.values()
        )
        manifest["auxiliary_image_count"] = (
            manifest["image_count"] - manifest["core_image_count"]
        )
        manifest["expected_face_instances"] = sum(
            int(annotation.get("expected_face_count", 0))
            for annotation in annotations.values()
        )
        manifest["core_expected_face_instances"] = sum(
            int(annotation.get("expected_face_count", 0))
            for annotation in annotations.values()
            if annotation.get("core")
        )

    def _write_private_json(self, path: Path, value: object) -> None:
        backup = path.with_name(f"{path.name}.pre-identity-annotation.bak")
        if not backup.exists() and path.exists():
            shutil.copy2(path, backup)
        temporary = path.with_name(f".{path.name}.{secrets.token_hex(8)}.tmp")
        try:
            temporary.write_text(
                json.dumps(value, indent=2, sort_keys=True) + "\n",
                encoding="utf-8",
            )
            temporary.replace(path)
        finally:
            temporary.unlink(missing_ok=True)


def region_records(faces: Sequence[DetectedFace]) -> list[dict[str, Any]]:
    """Discard embeddings and retain only stable index/display geometry."""
    return [
        {
            "face_index": index,
            "bounds": {
                "x": round(face.bounds.x, 3),
                "y": round(face.bounds.y, 3),
                "width": round(face.bounds.width, 3),
                "height": round(face.bounds.height, 3),
            },
        }
        for index, face in enumerate(faces)
    ]


def summarize_annotations(annotations: dict[str, dict[str, Any]]) -> dict[str, Any]:
    assignments = [
        item
        for annotation in annotations.values()
        for item in annotation.get("anonymous_identity_groups", [])
        if isinstance(item, dict)
    ]
    labelled = [item for item in assignments if item.get("status") == "labelled"]
    appearances: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for item in labelled:
        identity = item.get("identity_id")
        if isinstance(identity, str):
            appearances[identity].append(item)
    multiple = sorted(
        identity for identity, rows in appearances.items() if len(rows) > 1
    )
    cross_age = sorted(
        identity
        for identity, rows in appearances.items()
        if len({str(row.get("age_band", "unknown")) for row in rows} - {"unknown"}) > 1
    )
    all_flags = {
        str(flag)
        for item in assignments
        for flag in item.get("note_flags", [])
        if isinstance(flag, str)
    }
    identity_age_bands = {
        identity: {str(row.get("age_band", "unknown")) for row in rows}
        for identity, rows in appearances.items()
    }
    child_teen_adult = any(
        ("child" in bands or "teen" in bands)
        and bool(bands & {"adult-20s-30s", "adult-40s-50s", "adult-60-plus"})
        for bands in identity_age_bands.values()
    )
    twenty_forty_sixty = any(
        {"adult-20s-30s", "adult-40s-50s", "adult-60-plus"} <= bands
        for bands in identity_age_bands.values()
    )
    appearance_change = any(
        len(rows) > 1
        and any(
            flag in {"glasses", "facial-hair", "hairstyle-change"}
            for row in rows
            for flag in row.get("note_flags", [])
        )
        for rows in appearances.values()
    )
    coverage = {
        "child_teen_adult_progression": child_teen_adult,
        "twenty_forty_sixty_plus_progression": twenty_forty_sixty,
        "siblings": "sibling-resemblance" in all_flags,
        "parent_child_resemblance": "parent-child-resemblance" in all_flags,
        "old_or_damaged_scans": bool(all_flags & {"old-scan", "damaged-scan"}),
        "appearance_changes": appearance_change,
        "extreme_profiles_or_occlusion": bool(
            all_flags & {"extreme-profile", "occlusion"}
        ),
    }
    return {
        "total_images": len(annotations),
        "total_detected_faces": len(assignments),
        "annotated_faces": len(labelled),
        "unknown_faces": sum(item.get("status") == "unknown" for item in assignments),
        "skipped_faces": sum(item.get("status") == "skipped" for item in assignments),
        "remaining_unlabelled_faces": sum(
            item.get("status") == "unlabelled" for item in assignments
        ),
        "unique_anonymous_identities": len(appearances),
        "identities_with_multiple_appearances": multiple,
        "identities_with_cross_age_coverage": cross_age,
        "coverage": coverage,
        "coverage_gaps": [label for label, present in coverage.items() if not present],
    }


def create_app(store: BenchmarkAnnotationStore, token: str) -> FastAPI:
    app = FastAPI(
        title="Private face benchmark annotation helper",
        docs_url=None,
        redoc_url=None,
        openapi_url=None,
    )

    def require_token(supplied: str | None) -> None:
        if supplied is None or not secrets.compare_digest(supplied, token):
            raise HTTPException(status_code=403, detail="local session token required")

    @app.get("/", response_class=HTMLResponse)
    def index() -> HTMLResponse:
        template = Path(__file__).with_name("benchmark_annotation_helper.html")
        html = template.read_text(encoding="utf-8").replace(
            "__LOCAL_SESSION_TOKEN__", token
        )
        return HTMLResponse(html, headers={"Cache-Control": "no-store"})

    @app.get("/assets/{benchmark_id}")
    def asset(benchmark_id: str) -> FileResponse:
        try:
            path = store.asset(benchmark_id)
        except (KeyError, ValueError) as exception:
            raise HTTPException(status_code=404, detail=str(exception)) from exception
        return FileResponse(path, headers={"Cache-Control": "no-store"})

    @app.get("/api/state")
    def state() -> dict[str, Any]:
        return store.state_payload()

    @app.get("/api/images/{benchmark_id}")
    def image(benchmark_id: str) -> dict[str, Any]:
        try:
            return store.image_payload(benchmark_id)
        except (KeyError, ValueError) as exception:
            raise HTTPException(status_code=404, detail=str(exception)) from exception

    @app.put("/api/images/{benchmark_id}")
    def save(
        benchmark_id: str,
        payload: ImageAssignmentsInput,
        x_local_session: str | None = Header(default=None),
    ) -> dict[str, Any]:
        require_token(x_local_session)
        try:
            return store.save_assignments(benchmark_id, payload.assignments)
        except KeyError as exception:
            raise HTTPException(status_code=404, detail=str(exception)) from exception
        except ValueError as exception:
            raise HTTPException(status_code=422, detail=str(exception)) from exception

    return app


def _regions(annotation: dict[str, Any], benchmark_id: str) -> list[dict[str, Any]]:
    raw = annotation.get("face_regions")
    if not isinstance(raw, list):
        raise ValueError(
            f"{benchmark_id}: no stable face regions; run the local prepare command"
        )
    regions = [_object(item, f"{benchmark_id} face region") for item in raw]
    indices = [item.get("face_index") for item in regions]
    if indices != list(range(len(regions))):
        raise ValueError(f"{benchmark_id}: face region indices must be contiguous")
    return regions


def _normalised_assignments(
    annotation: dict[str, Any], regions: list[dict[str, Any]]
) -> list[dict[str, Any]]:
    raw = annotation.get("anonymous_identity_groups", [])
    if not isinstance(raw, list):
        raise ValueError("anonymous_identity_groups must be a list")
    by_index: dict[int, dict[str, Any]] = {}
    for item in raw:
        row = _object(item, "anonymous identity assignment")
        index = row.get("face_index")
        if not isinstance(index, int) or index in by_index:
            raise ValueError("face assignments require unique integer indices")
        by_index[index] = row
    return [
        by_index.get(index, _empty_assignment(index)) for index in range(len(regions))
    ]


def _empty_assignment(index: int) -> dict[str, Any]:
    return {
        "face_index": index,
        "status": "unlabelled",
        "identity_id": None,
        "age_band": "unknown",
        "note_flags": [],
        "private_note": "",
    }


def _read_json_object(path: Path) -> dict[str, Any]:
    value: object = json.loads(path.read_text(encoding="utf-8"))
    return _object(value, str(path))


def _object(value: object, label: str) -> dict[str, Any]:
    if not isinstance(value, dict) or any(not isinstance(key, str) for key in value):
        raise ValueError(f"{label} must be a JSON object with string keys")
    return value


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        while chunk := source.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def _image_dimensions(path: Path) -> tuple[int, int]:
    image = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_COLOR)
    if image is None or image.ndim != 3:
        raise ValueError(f"could not decode benchmark image {path.name}")
    height, width = image.shape[:2]
    return int(width), int(height)


def _print_summary(summary: dict[str, Any]) -> None:
    print(json.dumps(summary, indent=2, sort_keys=True))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--benchmark-root", type=Path, required=True)
    commands = parser.add_subparsers(dest="command", required=True)
    prepare = commands.add_parser("prepare", help="detect and persist local face boxes")
    prepare.add_argument("--insightface-root", type=Path, required=True)
    prepare.add_argument("--detection-threshold", type=float, default=0.6)
    import_assets = commands.add_parser(
        "import-assets", help="add unique private auxiliary images"
    )
    import_assets.add_argument("--incoming", type=Path, required=True)
    commands.add_parser(
        "validate", help="validate private identity annotations"
    ).add_argument("--require-complete", action="store_true")
    commands.add_parser("summary", help="show private annotation progress and gaps")
    serve = commands.add_parser("serve", help="serve the localhost-only annotation UI")
    serve.add_argument("--port", type=int, default=8765)
    args = parser.parse_args()

    store = BenchmarkAnnotationStore(args.benchmark_root)
    if args.command == "prepare":
        provider = InsightFaceProvider(
            InsightFaceSettings(
                insightface_root=args.insightface_root,
                detection_threshold=args.detection_threshold,
            )
        )
        count = store.prepare_regions(provider)
        print(f"Prepared stable face regions for {count} private benchmark images.")
        return 0
    if args.command == "import-assets":
        _print_summary(store.import_assets(args.incoming))
        return 0
    if args.command == "validate":
        errors = store.validate(require_complete=bool(args.require_complete))
        if errors:
            print("Private benchmark annotation validation failed:")
            for error in errors:
                print(f"- {error}")
            return 1
        print("Private benchmark annotation validation passed.")
        return 0
    if args.command == "summary":
        _print_summary(store.state_payload()["summary"])
        return 0
    if args.command == "serve":
        token = secrets.token_urlsafe(32)
        print(f"Local annotation helper: http://127.0.0.1:{args.port}")
        uvicorn.run(create_app(store, token), host="127.0.0.1", port=args.port)
        return 0
    raise RuntimeError("unreachable command")


if __name__ == "__main__":
    raise SystemExit(main())
