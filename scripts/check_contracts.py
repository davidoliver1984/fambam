#!/usr/bin/env python3
"""Validate repository-owned language-neutral contract documents."""

from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
EVENTS = ROOT / "contracts" / "events"
REQUIRED = {
    "face-analysis-common.schema.json",
    "face-analysis-result.schema.json",
    "image-analysis-requested.schema.json",
    "image-analysis-completed.schema.json",
    "image-analysis-failed.schema.json",
}


def fail(message: str) -> None:
    print(f"Contract validation failed: {message}", file=sys.stderr)
    raise SystemExit(1)


def references(value: Any) -> list[str]:
    if isinstance(value, dict):
        found = [value["$ref"]] if isinstance(value.get("$ref"), str) else []
        for child in value.values():
            found.extend(references(child))
        return found
    if isinstance(value, list):
        found = []
        for child in value:
            found.extend(references(child))
        return found
    return []


def main() -> None:
    if not EVENTS.is_dir() or not (ROOT / "contracts" / "http").is_dir():
        fail("contracts/events and contracts/http must exist")

    present = {path.name for path in EVENTS.glob("*.schema.json")}
    missing = REQUIRED - present
    if missing:
        fail(f"missing required event schemas: {', '.join(sorted(missing))}")

    documents: dict[str, dict[str, Any]] = {}
    identifiers: set[str] = set()
    for path in sorted(EVENTS.glob("*.schema.json")):
        try:
            document = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exception:
            fail(f"{path.relative_to(ROOT)} is not valid JSON: {exception}")
        if not isinstance(document, dict):
            fail(f"{path.relative_to(ROOT)} must contain one schema object")
        if document.get("$schema") != "https://json-schema.org/draft/2020-12/schema":
            fail(f"{path.relative_to(ROOT)} must use JSON Schema 2020-12")
        identifier = document.get("$id")
        if not isinstance(identifier, str) or identifier in identifiers:
            fail(f"{path.relative_to(ROOT)} must have a unique string $id")
        identifiers.add(identifier)
        documents[path.name] = document

    for filename, document in documents.items():
        for reference in references(document):
            target = reference.split("#", 1)[0]
            if target and target not in documents:
                fail(f"{filename} references missing schema {target}")

    print(f"Contract validation passed ({len(documents)} event schemas).")


if __name__ == "__main__":
    main()
