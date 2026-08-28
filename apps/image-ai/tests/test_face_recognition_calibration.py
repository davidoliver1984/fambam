import math

import numpy as np

from scripts.calibrate_face_recognition import (
    LabelledSample,
    build_suggestion_trials,
    cosine_distance,
    evaluate_clustering,
    select_clustering_threshold,
    select_suggestion_thresholds,
)


def test_cosine_distance_and_scene_isolation() -> None:
    samples = [
        _sample("a-1", "person-a", "scene-1", 0),
        _sample("a-2", "person-a", "scene-1", 2),
        _sample("a-3", "person-a", "scene-2", 4),
        _sample("b-1", "person-b", "scene-3", 90),
        _sample("b-2", "person-b", "scene-4", 94),
    ]

    assert cosine_distance(samples[0].embedding, samples[0].embedding) == 0
    trials = build_suggestion_trials(samples)
    first = next(trial for trial in trials if trial.expected_identity_id == "person-a")
    own = next(
        candidate
        for candidate in first.candidates
        if candidate.identity_id == "person-a"
    )
    assert own.reference_count == 1


def test_calibration_selects_zero_false_positive_suggestion_and_clusters() -> None:
    samples = [
        _sample("a-1", "person-a", "scene-a1", 0),
        _sample("a-2", "person-a", "scene-a2", 5),
        _sample("a-3", "person-a", "scene-a3", -5),
        _sample("b-1", "person-b", "scene-b1", 90),
        _sample("b-2", "person-b", "scene-b2", 95),
        _sample("b-3", "person-b", "scene-b3", 85),
        _sample("c-1", "person-c", "scene-c1", 120),
        _sample("c-2", "person-c", "scene-c2", 180),
    ]

    thresholds, suggestion = select_suggestion_thresholds(
        build_suggestion_trials(samples)
    )
    clustering_threshold, clustering = select_clustering_threshold(samples)

    assert thresholds["suggestion_minimum_strong_references"] == 2
    assert suggestion["strong_incorrect"] == 0
    assert suggestion["strong_correct"] > 0
    assert clustering["impure_cluster_count"] == 0
    assert clustering["pair_precision"] == 1.0
    assert clustering_threshold > 0
    assert evaluate_clustering(samples, clustering_threshold) == clustering


def _sample(
    sample_id: str, identity_id: str, scene_group: str, degrees: float
) -> LabelledSample:
    radians = math.radians(degrees)
    return LabelledSample(
        sample_id,
        identity_id,
        scene_group,
        np.asarray([math.cos(radians), math.sin(radians)], dtype=np.float32),
    )
