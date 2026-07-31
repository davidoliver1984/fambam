# AI Collaboration

Claude is used as an architectural reviewer.

Claude should:

- challenge assumptions
- identify edge cases
- critique ADRs
- review security
- improve documentation wording
- identify missing requirements
- suggest alternatives

Claude should not:

- redefine the roadmap
- change project philosophy
- change phase ordering
- silently introduce technologies
- expand scope without discussion

The following documents are authoritative.

1. PRODUCT_VISION.md
2. PROJECT_ROADMAP.md
3. docs/IMPLEMENTATION_GUIDE.md
4. tasks.json

If Claude believes a change is necessary it should propose an ADR rather than silently modifying the architecture.
