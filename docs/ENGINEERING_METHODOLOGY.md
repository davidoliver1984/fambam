# Engineering Methodology

> This document defines the engineering methodology used across all of my software projects.
>
> The objective is not to maximise AI-generated code.
>
> The objective is to maximise engineering quality while keeping the human responsible for every architectural and implementation decision.
>
> AI assists engineering.
>
> AI does not replace engineering.
>
> Engineering responsibility always remains with the human engineer.

---

# Core Philosophy

Every project follows the same principles.

- Product before technology.
- Architecture before implementation.
- Decisions before code.
- Documentation before complexity.
- Small, reviewable changes.
- Every important decision is reversible where practical.
- Human judgement always overrides AI suggestions.

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

## ChatGPT

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

ChatGPT helps determine **what should be built and why**.

---

## Claude

**Role:** Independent architectural reviewer.

Primary responsibilities:

- Challenge assumptions
- Review ADRs
- Identify edge cases
- Suggest alternatives
- Critique implementation plans
- Review completed implementation phases

Claude is intentionally used as an independent second opinion.

Agreement between AI models is never assumed.

Disagreement is investigated.

---

## David + Codex

**Role:** Implementation pair programming.

Responsibilities:

- Implement the currently accepted architecture
- Stay within the current implementation stage
- Produce focused commits
- Execute verification
- Update implementation documentation
- Never expand scope without discussion

Codex is not expected to redesign the project.

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
                 (ChatGPT)
                      │
                      ▼
         Independent Review
             (Claude)
                      │
                      ▼
             Accepted ADR
                (Commit)
                      │
                      ▼
           David + Codex
          phase-N-s01
          phase-N-s02
          phase-N-s03
                      │
                      ▼
        Independent Phase Review
             (Claude)
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
- Amount of AI-generated code
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