.DEFAULT_GOAL := help

.PHONY: help foundation-check docs-check contracts-check format-check lint typecheck test test-api test-web test-ai test-e2e security-check

help: ## List supported repository commands
	@awk 'BEGIN {FS = ":.*## "; printf "Family Photo Archive commands:\n\n"} /^[a-zA-Z0-9_-]+:.*## / {printf "  %-20s %s\n", $$1, $$2}' $(MAKEFILE_LIST)

foundation-check: ## Validate the Phase 0 repository foundation
	@python3 scripts/check_foundation.py

docs-check: ## Validate JSON, Markdown formatting and local documentation links
	@python3 scripts/check_foundation.py --docs-only

contracts-check: ## Validate the current contract directory structure
	@test -d contracts/events && test -d contracts/http
	@echo "Contract directory structure is valid (contracts are introduced in later stages)."

format-check: ## Run repository formatting checks available in the current phase
	@$(MAKE) --no-print-directory docs-check

lint: ## Run service linters (available after Phase 1 scaffolding)
	@echo "Unavailable: application linters are introduced in Phase 1." >&2
	@exit 2

typecheck: ## Run service type checks (available after Phase 1 scaffolding)
	@echo "Unavailable: application type checks are introduced in Phase 1." >&2
	@exit 2

test: ## Run all application tests (available after Phase 1 scaffolding)
	@echo "Unavailable: application tests are introduced in Phase 1." >&2
	@exit 2

test-api: ## Run API tests (available after the Laravel API is scaffolded)
	@echo "Unavailable: apps/api is scaffolded in FPA-P01-S02." >&2
	@exit 2

test-web: ## Run web tests (available after the web application is scaffolded)
	@echo "Unavailable: apps/web is scaffolded in FPA-P01-S02." >&2
	@exit 2

test-ai: ## Run image-analysis tests (available after the service is scaffolded)
	@echo "Unavailable: apps/image-ai is scaffolded in FPA-P01-S02." >&2
	@exit 2

test-e2e: ## Run end-to-end tests (available after Phase 1 scaffolding)
	@echo "Unavailable: end-to-end tests are introduced after application scaffolding." >&2
	@exit 2

security-check: ## Run automated security checks (available after dependencies exist)
	@echo "Unavailable: dependency security checks are introduced with each application." >&2
	@exit 2
