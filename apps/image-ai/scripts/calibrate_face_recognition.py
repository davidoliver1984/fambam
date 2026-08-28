"""Calibrate private face-recognition thresholds without persisting biometrics."""

from __future__ import annotations

import argparse
import hashlib
import json
import math
from collections import defaultdict
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np

from app.face_analysis.insightface_provider import (
    InsightFaceProvider,
    InsightFaceSettings,
)

PROFILE_ID = "buffalo-l-v0.7-private-family-v1"
GRID = tuple(round(value / 1000, 3) for value in range(25, 1001, 5))
MARGIN_GRID = tuple(round(value / 1000, 3) for value in range(0, 301, 5))
EMPIRICAL_SAFETY_BUFFER = 0.025
MINIMUM_STRONG_REFERENCES = 2
MINIMUM_AMBIGUITY_MARGIN = 0.1
MAXIMUM_FALSE_ONLY_SHORTLIST_RATE = 0.02
MINIMUM_SHORTLIST_CANDIDATE_PRECISION = 0.9
MAXIMUM_AVERAGE_SHORTLIST_SIZE = 3.0


@dataclass(frozen=True)
class LabelledSample:
    sample_id: str
    identity_id: str
    scene_group: str
    embedding: np.ndarray[Any, np.dtype[np.float32]]


@dataclass(frozen=True)
class Candidate:
    identity_id: str
    best_distance: float
    reference_count: int


@dataclass(frozen=True)
class SuggestionTrial:
    expected_identity_id: str
    candidates: tuple[Candidate, ...]


def cosine_distance(
    left: np.ndarray[Any, np.dtype[np.float32]],
    right: np.ndarray[Any, np.dtype[np.float32]],
) -> float:
    denominator = float(np.linalg.norm(left) * np.linalg.norm(right))
    if denominator == 0 or not math.isfinite(denominator):
        raise ValueError("face embedding has no finite cosine norm")
    distance = 1.0 - float(np.dot(left, right) / denominator)
    if not math.isfinite(distance):
        raise ValueError("face embedding produced a non-finite cosine distance")
    return max(0.0, min(2.0, distance))


def build_suggestion_trials(
    samples: list[LabelledSample], *, maximum_results: int = 100
) -> list[SuggestionTrial]:
    trials: list[SuggestionTrial] = []
    for query in samples:
        references = [
            reference
            for reference in samples
            if reference.scene_group != query.scene_group
        ]
        if not any(
            reference.identity_id == query.identity_id for reference in references
        ):
            continue
        ranked = sorted(
            (
                (cosine_distance(query.embedding, reference.embedding), reference)
                for reference in references
            ),
            key=lambda item: (item[0], item[1].identity_id, item[1].sample_id),
        )[:maximum_results]
        grouped: dict[str, list[float]] = defaultdict(list)
        for distance, reference in ranked:
            grouped[reference.identity_id].append(distance)
        candidates = tuple(
            sorted(
                (
                    Candidate(identity_id, min(distances), len(distances))
                    for identity_id, distances in grouped.items()
                ),
                key=lambda candidate: (
                    candidate.best_distance,
                    candidate.identity_id,
                ),
            )
        )
        trials.append(SuggestionTrial(query.identity_id, candidates))
    return trials


def select_suggestion_thresholds(
    trials: list[SuggestionTrial],
) -> tuple[dict[str, float | int], dict[str, Any]]:
    if not trials:
        raise ValueError("recognition calibration has no independent-scene trials")

    shortlist_candidates: list[tuple[int, int, float, float, float]] = []
    for threshold in GRID:
        hits = 0
        false_only = 0
        candidate_slots = 0
        for trial in trials:
            shortlist = tuple(
                candidate
                for candidate in trial.candidates
                if candidate.best_distance <= threshold
            )
            if not shortlist:
                continue
            candidate_slots += len(shortlist)
            if any(
                candidate.identity_id == trial.expected_identity_id
                for candidate in shortlist
            ):
                hits += 1
            else:
                false_only += 1
        shown = hits + false_only
        average_size = candidate_slots / max(shown, 1)
        precision = hits / max(candidate_slots, 1)
        if (
            false_only / len(trials) <= MAXIMUM_FALSE_ONLY_SHORTLIST_RATE
            and precision >= MINIMUM_SHORTLIST_CANDIDATE_PRECISION
            and average_size <= MAXIMUM_AVERAGE_SHORTLIST_SIZE
        ):
            shortlist_candidates.append(
                (hits, -false_only, precision, -average_size, threshold)
            )
    if not shortlist_candidates:
        raise ValueError("no shortlist threshold met the conservative quality floor")
    _, _, _, _, shortlist_threshold = max(
        shortlist_candidates,
        key=lambda item: (item[0], item[1], item[2], item[3], -item[4]),
    )

    incorrect_top_distances = [
        trial.candidates[0].best_distance
        for trial in trials
        if trial.candidates[0].identity_id != trial.expected_identity_id
    ]
    if not incorrect_top_distances:
        raise ValueError("benchmark needs at least one hard incorrect top candidate")
    strong_safe_limit = min(incorrect_top_distances) - EMPIRICAL_SAFETY_BUFFER
    strong_candidates: list[tuple[int, float, float, int]] = []
    for strong_threshold in (
        value
        for value in GRID
        if value <= shortlist_threshold and value <= strong_safe_limit
    ):
        for margin in (
            value for value in MARGIN_GRID if value >= MINIMUM_AMBIGUITY_MARGIN
        ):
            correct = 0
            incorrect = 0
            for trial in trials:
                shortlist = tuple(
                    candidate
                    for candidate in trial.candidates
                    if candidate.best_distance <= shortlist_threshold
                )
                if not shortlist:
                    continue
                best = shortlist[0]
                runner_up = shortlist[1] if len(shortlist) > 1 else None
                unambiguous = (
                    runner_up is None
                    or runner_up.best_distance - best.best_distance >= margin
                )
                if (
                    best.best_distance <= strong_threshold
                    and best.reference_count >= MINIMUM_STRONG_REFERENCES
                    and unambiguous
                ):
                    if best.identity_id == trial.expected_identity_id:
                        correct += 1
                    else:
                        incorrect += 1
            if incorrect == 0:
                strong_candidates.append((correct, strong_threshold, margin, incorrect))
    if not strong_candidates:
        raise ValueError("no strong-suggestion settings achieved zero false positives")
    correct, strong_threshold, margin, _ = max(
        strong_candidates,
        key=lambda item: (item[0], -item[1], item[2]),
    )
    thresholds: dict[str, float | int] = {
        "suggestion_strong_max_cosine_distance": strong_threshold,
        "suggestion_shortlist_max_cosine_distance": shortlist_threshold,
        "suggestion_ambiguity_margin": margin,
        "suggestion_minimum_strong_references": MINIMUM_STRONG_REFERENCES,
    }
    metrics = evaluate_suggestions(trials, thresholds)
    metrics["selected_strong_correct"] = correct
    return thresholds, metrics


def suggestion_curve(trials: list[SuggestionTrial]) -> list[dict[str, Any]]:
    curve: list[dict[str, Any]] = []
    for threshold in (round(value / 100, 2) for value in range(25, 101, 5)):
        hits = 0
        false_only = 0
        no_suggestion = 0
        candidate_slots = 0
        for trial in trials:
            shortlist = tuple(
                candidate
                for candidate in trial.candidates
                if candidate.best_distance <= threshold
            )
            if not shortlist:
                no_suggestion += 1
                continue
            candidate_slots += len(shortlist)
            if any(
                candidate.identity_id == trial.expected_identity_id
                for candidate in shortlist
            ):
                hits += 1
            else:
                false_only += 1
        shown = hits + false_only
        curve.append(
            {
                "threshold": threshold,
                "expected_identity_included": hits,
                "false_only_shortlists": false_only,
                "no_suggestion": no_suggestion,
                "average_shortlist_size": round(candidate_slots / max(shown, 1), 6),
                "candidate_slot_precision": _ratio(hits, candidate_slots),
            }
        )
    return curve


def distance_evidence(
    samples: list[LabelledSample], trials: list[SuggestionTrial]
) -> dict[str, Any]:
    positive_pairs: list[float] = []
    negative_pairs: list[float] = []
    for index, left in enumerate(samples):
        for right in samples[index + 1 :]:
            if left.scene_group == right.scene_group:
                continue
            distance = cosine_distance(left.embedding, right.embedding)
            if left.identity_id == right.identity_id:
                positive_pairs.append(distance)
            else:
                negative_pairs.append(distance)
    own_best: list[float] = []
    nearest_impostor: list[float] = []
    correct_top_margins: list[float] = []
    incorrect_top_distances: list[float] = []
    incorrect_top_margins: list[float] = []
    for trial in trials:
        own = next(
            candidate
            for candidate in trial.candidates
            if candidate.identity_id == trial.expected_identity_id
        )
        impostors = [
            candidate
            for candidate in trial.candidates
            if candidate.identity_id != trial.expected_identity_id
        ]
        own_best.append(own.best_distance)
        if impostors:
            nearest_impostor.append(impostors[0].best_distance)
        best = trial.candidates[0]
        runner_up = trial.candidates[1] if len(trial.candidates) > 1 else None
        margin = (
            runner_up.best_distance - best.best_distance
            if runner_up is not None
            else 2.0 - best.best_distance
        )
        if best.identity_id == trial.expected_identity_id:
            correct_top_margins.append(margin)
        else:
            incorrect_top_distances.append(best.best_distance)
            incorrect_top_margins.append(margin)
    return {
        "independent_positive_pair_count": len(positive_pairs),
        "independent_negative_pair_count": len(negative_pairs),
        "positive_pair_cosine_distance": _distribution(positive_pairs),
        "negative_pair_cosine_distance": _distribution(negative_pairs),
        "own_identity_best_cosine_distance": _distribution(own_best),
        "nearest_impostor_cosine_distance": _distribution(nearest_impostor),
        "correct_top_candidate_margin": _distribution(correct_top_margins),
        "incorrect_top_candidate_count": len(incorrect_top_distances),
        "incorrect_top_candidate_distance": _distribution(incorrect_top_distances),
        "incorrect_top_candidate_margin": _distribution(incorrect_top_margins),
    }


def evaluate_suggestions(
    trials: list[SuggestionTrial], thresholds: dict[str, float | int]
) -> dict[str, Any]:
    strong_correct = 0
    strong_incorrect = 0
    shortlist_with_expected = 0
    shortlist_without_expected = 0
    no_suggestion = 0
    shortlist_slots = 0
    for trial in trials:
        shortlist = tuple(
            candidate
            for candidate in trial.candidates
            if candidate.best_distance
            <= float(thresholds["suggestion_shortlist_max_cosine_distance"])
        )
        if not shortlist:
            no_suggestion += 1
            continue
        best = shortlist[0]
        runner_up = shortlist[1] if len(shortlist) > 1 else None
        unambiguous = runner_up is None or (
            runner_up.best_distance - best.best_distance
            >= float(thresholds["suggestion_ambiguity_margin"])
        )
        if (
            best.best_distance
            <= float(thresholds["suggestion_strong_max_cosine_distance"])
            and best.reference_count
            >= int(thresholds["suggestion_minimum_strong_references"])
            and unambiguous
        ):
            if best.identity_id == trial.expected_identity_id:
                strong_correct += 1
            else:
                strong_incorrect += 1
            continue
        shortlist_slots += len(shortlist)
        if any(
            candidate.identity_id == trial.expected_identity_id
            for candidate in shortlist
        ):
            shortlist_with_expected += 1
        else:
            shortlist_without_expected += 1
    strong_total = strong_correct + strong_incorrect
    return {
        "independent_scene_query_trials": len(trials),
        "strong_correct": strong_correct,
        "strong_incorrect": strong_incorrect,
        "strong_precision": _ratio(strong_correct, strong_total, empty=1.0),
        "strong_coverage": _ratio(strong_correct, len(trials)),
        "shortlists_containing_expected_identity": shortlist_with_expected,
        "shortlists_without_expected_identity": shortlist_without_expected,
        "average_shortlist_size": round(
            shortlist_slots
            / max(shortlist_with_expected + shortlist_without_expected, 1),
            6,
        ),
        "no_suggestion": no_suggestion,
    }


def select_clustering_threshold(
    samples: list[LabelledSample],
) -> tuple[float, dict[str, Any]]:
    representatives: dict[tuple[str, str], LabelledSample] = {}
    for sample in sorted(samples, key=lambda value: value.sample_id):
        representatives.setdefault((sample.identity_id, sample.scene_group), sample)
    candidate_samples = list(representatives.values())
    negative_distances = [
        cosine_distance(left.embedding, right.embedding)
        for index, left in enumerate(candidate_samples)
        for right in candidate_samples[index + 1 :]
        if left.identity_id != right.identity_id
    ]
    if not negative_distances:
        raise ValueError("clustering calibration needs different-identity examples")
    safe_limit = min(negative_distances) - EMPIRICAL_SAFETY_BUFFER
    best: tuple[int, float, dict[str, Any]] | None = None
    for threshold in (value for value in GRID if value <= safe_limit):
        metrics = evaluate_clustering(candidate_samples, threshold)
        if metrics["impure_cluster_count"] != 0:
            continue
        candidate = (int(metrics["correct_clustered_pairs"]), threshold, metrics)
        if best is None or (candidate[0], -candidate[1]) > (best[0], -best[1]):
            best = candidate
    if best is None:
        raise ValueError("no clustering threshold produced identity-pure clusters")
    return best[1], best[2]


def evaluate_clustering(
    samples: list[LabelledSample], threshold: float
) -> dict[str, Any]:
    ordered = sorted(samples, key=lambda value: value.sample_id)
    distances = {
        _pair_key(left.sample_id, right.sample_id): cosine_distance(
            left.embedding, right.embedding
        )
        for index, left in enumerate(ordered)
        for right in ordered[index + 1 :]
    }
    clusters: list[list[LabelledSample]] = []
    for sample in ordered:
        for cluster in clusters:
            if all(
                distances[_pair_key(sample.sample_id, member.sample_id)] <= threshold
                for member in cluster
            ):
                cluster.append(sample)
                break
        else:
            clusters.append([sample])
    clusters = [cluster for cluster in clusters if len(cluster) >= 2]
    impure = sum(
        len({sample.identity_id for sample in cluster}) > 1 for cluster in clusters
    )
    correct_pairs = sum(
        left.identity_id == right.identity_id
        for cluster in clusters
        for index, left in enumerate(cluster)
        for right in cluster[index + 1 :]
    )
    incorrect_pairs = sum(
        left.identity_id != right.identity_id
        for cluster in clusters
        for index, left in enumerate(cluster)
        for right in cluster[index + 1 :]
    )
    possible_pairs = sum(
        left.identity_id == right.identity_id
        for index, left in enumerate(ordered)
        for right in ordered[index + 1 :]
    )
    return {
        "independent_scene_samples": len(ordered),
        "cluster_count": len(clusters),
        "clustered_samples": sum(len(cluster) for cluster in clusters),
        "pure_cluster_count": len(clusters) - impure,
        "impure_cluster_count": impure,
        "correct_clustered_pairs": correct_pairs,
        "incorrect_clustered_pairs": incorrect_pairs,
        "pair_precision": _ratio(correct_pairs, correct_pairs + incorrect_pairs),
        "pair_recall": _ratio(correct_pairs, possible_pairs),
    }


def calibrate(
    benchmark_root: Path,
    insightface_root: Path,
    *,
    detection_threshold: float,
) -> dict[str, Any]:
    root = benchmark_root.resolve()
    annotations = _object(
        json.loads((root / "annotations.json").read_text(encoding="utf-8")),
        "annotations",
    )
    provider = InsightFaceProvider(
        InsightFaceSettings(
            insightface_root=insightface_root,
            detection_threshold=detection_threshold,
        )
    )
    samples: list[LabelledSample] = []
    labelled_identity_ids: set[str] = set()
    labelled_scenes: set[str] = set()
    exact_hashes: set[str] = set()
    detected_faces = 0
    for benchmark_id, raw_annotation in sorted(annotations.items()):
        annotation = _object(raw_annotation, benchmark_id)
        matches = sorted((root / "assets").glob(f"{benchmark_id}.*"))
        if len(matches) != 1:
            raise ValueError(f"{benchmark_id}: expected exactly one private asset")
        asset = matches[0]
        exact_hashes.add(_sha256(asset))
        faces = list(provider.analyze(asset))
        regions = annotation.get("face_regions")
        if not isinstance(regions, list) or len(regions) != len(faces):
            raise ValueError(
                f"{benchmark_id}: live detections no longer match stable face indices"
            )
        detected_faces += len(faces)
        assignments = annotation.get("anonymous_identity_groups")
        if not isinstance(assignments, list):
            raise ValueError(f"{benchmark_id}: identity assignments must be a list")
        scene_group = str(annotation.get("scene_group", benchmark_id))
        for item in assignments:
            assignment = _object(item, f"{benchmark_id} identity assignment")
            if assignment.get("status") != "labelled":
                continue
            index = assignment.get("face_index")
            identity_id = assignment.get("identity_id")
            if not isinstance(index, int) or not isinstance(identity_id, str):
                raise ValueError(f"{benchmark_id}: invalid labelled assignment")
            face = faces[index]
            samples.append(
                LabelledSample(
                    sample_id=f"{benchmark_id}:{index}",
                    identity_id=identity_id,
                    scene_group=scene_group,
                    embedding=np.asarray(face.embedding, dtype=np.float32),
                )
            )
            labelled_identity_ids.add(identity_id)
            labelled_scenes.add(scene_group)

    trials = build_suggestion_trials(samples)
    thresholds, suggestion_metrics = select_suggestion_thresholds(trials)
    clustering_threshold, clustering_metrics = select_clustering_threshold(samples)
    thresholds["clustering_max_cosine_distance"] = clustering_threshold
    identity = provider.identity.model_dump(mode="json")
    corpus_checksum = _sha256(root / "annotations.json")
    return {
        "schema_version": 1,
        "privacy": "private-local-aggregate-evidence-only-do-not-commit",
        "calibration_profile": {
            "identifier": PROFILE_ID,
            "provider_identity": identity,
            "benchmark_annotations_sha256": corpus_checksum,
            "thresholds": thresholds,
            "aggregation": "best-match-per-person-over-top-100-references",
            "evaluation_split": "leave-one-scene-group-out",
            "selection_policy": {
                "empirical_cosine_distance_safety_buffer": EMPIRICAL_SAFETY_BUFFER,
                "maximum_false_only_shortlist_rate": (
                    MAXIMUM_FALSE_ONLY_SHORTLIST_RATE
                ),
                "minimum_shortlist_candidate_precision": (
                    MINIMUM_SHORTLIST_CANDIDATE_PRECISION
                ),
                "maximum_average_shortlist_size": (MAXIMUM_AVERAGE_SHORTLIST_SIZE),
                "minimum_ambiguity_margin": MINIMUM_AMBIGUITY_MARGIN,
                "minimum_strong_references": MINIMUM_STRONG_REFERENCES,
                "strong_and_clustering_false_positive_tolerance": 0,
            },
            "activation_accepted": False,
        },
        "corpus": {
            "images": len(annotations),
            "unique_asset_hashes": len(exact_hashes),
            "detected_faces": detected_faces,
            "human_labelled_faces": len(samples),
            "anonymous_identities": len(labelled_identity_ids),
            "labelled_scene_groups": len(labelled_scenes),
        },
        "suggestion_evidence": suggestion_metrics,
        "suggestion_curve": suggestion_curve(trials),
        "distance_evidence": distance_evidence(samples, trials),
        "clustering_evidence": clustering_metrics,
        "limitations": [
            "Family-specific private benchmark; thresholds require recalibration "
            "after a material model, provider, preprocessing, or corpus change.",
            "Unknown and skipped faces cannot contribute identity-labelled "
            "accuracy evidence.",
            "Strong suggestions remain pending human review and never "
            "auto-approve identity.",
        ],
    }


def _ratio(numerator: int, denominator: int, *, empty: float = 0.0) -> float:
    return round(numerator / denominator, 6) if denominator else empty


def _distribution(values: list[float]) -> dict[str, float]:
    if not values:
        return {}
    array = np.asarray(values, dtype=np.float64)
    return {
        "minimum": round(float(np.min(array)), 6),
        "p05": round(float(np.percentile(array, 5)), 6),
        "p25": round(float(np.percentile(array, 25)), 6),
        "median": round(float(np.median(array)), 6),
        "p75": round(float(np.percentile(array, 75)), 6),
        "p95": round(float(np.percentile(array, 95)), 6),
        "maximum": round(float(np.max(array)), 6),
    }


def _pair_key(first: str, second: str) -> tuple[str, str]:
    return (first, second) if first < second else (second, first)


def _object(value: object, label: str) -> dict[str, Any]:
    if not isinstance(value, dict):
        raise ValueError(f"{label} must be a JSON object")
    return {str(key): item for key, item in value.items()}


def _sha256(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as source:
        while chunk := source.read(1024 * 1024):
            digest.update(chunk)
    return digest.hexdigest()


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--benchmark-root", type=Path, required=True)
    parser.add_argument("--insightface-root", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--detection-threshold", type=float, default=0.6)
    args = parser.parse_args()
    report = calibrate(
        args.benchmark_root,
        args.insightface_root,
        detection_threshold=args.detection_threshold,
    )
    output = args.output.resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(
        json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8"
    )
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
