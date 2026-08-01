#!/usr/bin/env python3
"""Dependency-free validation for the Phase 0 repository foundation."""

from __future__ import annotations

import argparse
import json
import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
IGNORED_DIRECTORIES = {
    ".git",
    ".venv",
    "gpt_drafts",
    "node_modules",
    "vendor",
}
MARKDOWN_LINK = re.compile(r"(?<!!)\[[^\]]+\]\(([^)]+)\)")
REQUIRED_DIRECTORIES = (
    "apps/web",
    "apps/api",
    "apps/image-ai",
    "contracts/events",
    "contracts/http",
    "docs/adr",
    "docs/journal",
    "infrastructure/docker",
    "infrastructure/terraform",
    "scripts",
    "tests/e2e",
)
REQUIRED_FILES = (
    "README.md",
    "CONTRIBUTING.md",
    "CLAUDE.md",
    "PRODUCT_VISION.md",
    "PROJECT_ROADMAP.md",
    "tasks.json",
    "Makefile",
    "docs/IMPLEMENTATION_GUIDE.md",
    "docs/ENGINEERING_METHODOLOGY.md",
    "docs/adr/template.md",
    "docs/journal/template.md",
    ".github/workflows/foundation.yml",
)


def repository_files(suffix: str) -> list[Path]:
    return sorted(
        path
        for path in ROOT.rglob(f"*{suffix}")
        if not any(part in IGNORED_DIRECTORIES for part in path.relative_to(ROOT).parts)
    )


def validate_json(errors: list[str]) -> None:
    for path in repository_files(".json"):
        if path.name.startswith("tsconfig"):
            # TypeScript configuration is JSON-with-comments and is validated
            # by the TypeScript compiler rather than the strict JSON parser.
            continue
        try:
            with path.open(encoding="utf-8") as handle:
                json.load(handle)
        except (OSError, json.JSONDecodeError) as error:
            errors.append(f"{path.relative_to(ROOT)}: invalid JSON: {error}")


def validate_markdown(errors: list[str]) -> None:
    for path in repository_files(".md"):
        text = path.read_text(encoding="utf-8")
        lines = text.splitlines()

        if not text.endswith("\n"):
            errors.append(f"{path.relative_to(ROOT)}: missing final newline")

        for line_number, line in enumerate(lines, start=1):
            if line.rstrip() != line:
                errors.append(
                    f"{path.relative_to(ROOT)}:{line_number}: trailing whitespace"
                )

            for target in MARKDOWN_LINK.findall(line):
                destination = target.split("#", 1)[0].strip()
                if not destination or destination.startswith(("http://", "https://", "mailto:")):
                    continue
                if destination.startswith("<") and destination.endswith(">"):
                    destination = destination[1:-1]
                resolved = (path.parent / destination).resolve()
                try:
                    resolved.relative_to(ROOT)
                except ValueError:
                    errors.append(
                        f"{path.relative_to(ROOT)}:{line_number}: link leaves repository: {target}"
                    )
                    continue
                if not resolved.exists():
                    errors.append(
                        f"{path.relative_to(ROOT)}:{line_number}: broken local link: {target}"
                    )


def validate_scaffold(errors: list[str]) -> None:
    for relative_path in REQUIRED_DIRECTORIES:
        if not (ROOT / relative_path).is_dir():
            errors.append(f"missing required directory: {relative_path}")
    for relative_path in REQUIRED_FILES:
        if not (ROOT / relative_path).is_file():
            errors.append(f"missing required file: {relative_path}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--docs-only",
        action="store_true",
        help="validate JSON and Markdown without checking the repository scaffold",
    )
    arguments = parser.parse_args()

    errors: list[str] = []
    validate_json(errors)
    validate_markdown(errors)
    if not arguments.docs_only:
        validate_scaffold(errors)

    if errors:
        print("Foundation validation failed:", file=sys.stderr)
        for error in errors:
            print(f"- {error}", file=sys.stderr)
        return 1

    scope = "Documentation" if arguments.docs_only else "Foundation"
    print(f"{scope} validation passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
