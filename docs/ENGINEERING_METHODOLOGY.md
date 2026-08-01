# Engineering Methodology

> This document defines the engineering methodology used across Fambam.
>
> The objective is not to maximise automatically generated code.
>
> The objective is to maximise engineering quality while keeping the human responsible for every architectural and implementation decision.
>
> Automation supports engineering.
>
> Engineering responsibility always remains with the project owner.

---

# Core Philosophy

Fambam follows these principles.

- Product before technology.
- Architecture before implementation.
- Decisions before code.
- Documentation before complexity.
- Small, reviewable changes.
- Every important decision is reversible where practical.
- Human judgement remains authoritative.

---

# Engineering Roles

## David

**Role:** Project owner and engineering lead.

Responsible for:

- Product vision
- Roadmap
- Acceptance criteria
- Architectural ownership
- Technical trade-offs
- Implementation decisions
- Code review
- Testing
- Releases

Every commit merged into the repository is ultimately David's responsibility.

---

## Architecture and product review

**Role:** Architect, product owner, mentor and long-term technical advisor.

Primary responsibilities:

- Product vision
- System architecture
- Domain modelling
- Roadmap creation
- Feature prioritisation
- Engineering trade-offs
- Security discussions
- Scalability discussions
- Long-term consistency

Architecture and product review determine **what should be built and why**.

---

## Independent architectural review

**Role:** Independent architectural reviewer.

Primary responsibilities:

- Challenge assumptions
- Review ADRs
- Identify edge cases
- Suggest alternatives
- Critique implementation plans
- Review completed implementation phases

Independent review provides a deliberate second opinion.

Agreement between reviewers is never assumed.

Disagreement is investigated.

---

## Implementation and verification

**Role:** Implementation and verification.

Responsibilities:

- Implement the currently accepted architecture
- Stay within the current implementation stage
- Produce focused commits
- Execute verification
- Update implementation documentation
- Never expand scope without discussion

Implementation does not redesign accepted architecture.

Implementation follows accepted architecture.

David remains responsible for every implementation decision.

---

# Development Lifecycle

Every significant architectural change follows the same lifecycle.

```text
                Product Vision
                      │
                      ▼
          Architecture Discussion
          (Architecture review)
                      │
                      ▼
         Independent Review
       (Independent review)
                      │
                      ▼
             Accepted ADR
                (Commit)
                      │
                      ▼
    Implementation and verification
          phase-N-s01
          phase-N-s02
          phase-N-s03
                      │
                      ▼
        Independent Phase Review
       (Independent review)
                      │
                      ▼
       Documentation & Verification
                      │
                      ▼
      Completed Roadmap Stage
      (Git tag: phase-N-sNN)
                      │
                      ▼
             Next Architectural ADR
```

Each implementation stage must be fully reviewed and accepted before progressing.

---

# ADR Philosophy

Architectural Decision Records are mandatory for important decisions.

Examples include:

- Service boundaries
- Database strategy
- Tenancy
- Authentication
- Storage
- AI providers
- Observability
- Deployment
- Security

Implementation must not begin until the ADR has been accepted.

---

# Implementation Stages

Implementation follows the project's roadmap.

Each completed roadmap stage receives an annotated Git tag using the format:

```
phase-N-sNN
```

where:

- `N` is the roadmap phase.
- `NN` is the completed roadmap stage.

The annotated `phase-N` tag is created only after the entire roadmap phase has successfully completed its acceptance gate.

Intermediate commits within a stage are intentionally left untagged.

If a roadmap stage consists solely of accepting an ADR, the ADR commit is also the stage completion commit and receives the appropriate stage tag.

Implementation stages should remain small enough to:

- Understand quickly
- Review independently
- Revert safely

---

# Documentation Hierarchy

Every document has one responsibility.

```
README.md
│
└── What is this repository?

↓

PRODUCT_VISION.md
│
└── Why does this project exist?

↓

PROJECT_ROADMAP.md
│
└── What will be built?

↓

IMPLEMENTATION_GUIDE.md
│
└── How will it be built?

↓

tasks.json
│
└── What is the current implementation stage?

↓

ADR
│
└── Why was this decision made?

↓

Journal
│
└── What actually happened?
```

---

# AI Principles

AI suggestions are proposals.

Not facts.

Every architectural decision should be explainable without referencing an AI.

If a decision cannot be defended independently, it should not be accepted.

AI assists engineering.

Human judgement remains authoritative.

---

# Commit Philosophy

Each commit should represent one logical change.

Avoid:

- Unrelated cleanup
- Hidden refactors
- Mixed concerns

Every commit should tell a clear story.

---

# Success Criteria

Success is not measured by:

- Lines of code
- Amount of automatically generated code
- Number of repositories

Success is measured by:

- Maintainability
- Architectural clarity
- Security
- Correctness
- Testability
- Documentation
- Ability to explain every decision

---

# Long-Term Goal

Build software that another engineer could confidently understand and extend five years from now.

The engineering process should be as reusable and maintainable as the software itself.
