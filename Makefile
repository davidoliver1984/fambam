.DEFAULT_GOAL := help

.PHONY: help foundation-check docs-check contracts-check format format-check lint typecheck test test-api test-web test-ai test-e2e security-check

help: ## List supported repository commands
	@awk 'BEGIN {FS = ":.*## "; printf "Family Photo Archive commands:\n\n"} /^[a-zA-Z0-9_-]+:.*## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

foundation-check: ## Validate the Phase 0 repository foundation
	@python3 scripts/check_foundation.py

docs-check: ## Validate JSON, Markdown formatting and local documentation links
	@python3 scripts/check_foundation.py --docs-only

contracts-check: ## Validate the current contract directory structure
	@test -d contracts/events && test -d contracts/http
	@echo "Contract directory structure is valid (contracts are introduced in later stages)."

format: ## Format all application source files
	@cd apps/web && npm run format
	@cd apps/api && composer format
	@cd apps/image-ai && .venv/bin/ruff format .

format-check: ## Check repository and application formatting
	@$(MAKE) --no-print-directory docs-check
	@cd apps/web && npm run format:check
	@cd apps/api && composer format:check
	@cd apps/image-ai && .venv/bin/ruff format --check .

lint: ## Run all application linters
	@cd apps/web && npm run lint
	@cd apps/image-ai && .venv/bin/ruff check .

typecheck: ## Run all application type checks
	@cd apps/web && npm run typecheck
	@cd apps/api && composer typecheck
	@cd apps/image-ai && .venv/bin/mypy

test: test-web test-api test-ai ## Run all application tests

test-api: ## Run Laravel API tests
	@cd apps/api && composer test

test-web: ## Run React web tests
	@cd apps/web && npm test

test-ai: ## Run Python image-analysis tests
	@cd apps/image-ai && .venv/bin/pytest

test-e2e: ## Run end-to-end tests (available after Phase 1 scaffolding)
	@echo "Unavailable: end-to-end tests are introduced after application scaffolding." >&2
	@exit 2

security-check: ## Run dependency security checks
	@cd apps/web && npm audit
	@cd apps/api && composer audit
