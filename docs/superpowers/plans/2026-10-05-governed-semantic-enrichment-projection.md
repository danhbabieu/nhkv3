# Governed Semantic Enrichment Projection Implementation Plan

**Goal:** Add governed, bounded semantic/lexical relation operations and dynamic read projection without mutating live data.

**Architecture:** Reuse existing Graph, Dictionary and Governance abstractions. Add deterministic operation adapters and a read-only facet/query seam; never persist projection packets or duplicate owner payload.

**Constraints:** Dictionary is never a Graph endpoint; Graph owns semantic relations; Dictionary owns lexical relations; all mutations use Governance/CAS/idempotency/read-back; facets are bounded/read-time; no canary or live data mutation.

### Task 1: Governed operation adapters
- [ ] Add RED tests for Graph ADD/REPLACE/RETIRE/REACTIVATE, exact fingerprints, registry/revision drift, idempotency and read-back.
- [ ] Implement the minimal Graph Governance adapter over GraphService and GraphRelationContext.
- [ ] Add RED tests for Dictionary lexical lifecycle, Entry/Sense validation, inverse/symmetric policy, no Graph/semantic_reference side effects and proposal-only similarity.
- [ ] Implement the Dictionary Governance adapter over the lexical repository and endpoint/sense resolvers.

### Task 2: Dynamic facet/read model
- [ ] Add RED tests for exact type/family mapping, conflicts, deterministic registry hash, public eligibility, direct-before-derived, two-hop bound, dedupe, retirement, stale owners and truncation.
- [ ] Implement DictionaryRelationFacetRegistry for the ten approved facets.
- [ ] Implement a bounded read-only DictionarySemanticEnrichmentQuery with live owner payload resolution.

### Task 3: Dictionary detail and related terms
- [ ] Add RED tests for relation_facets, compatibility aliases, missing semantic_reference, Sense isolation and explicit lexical precedence.
- [ ] Integrate the generic packet into DictionaryDetailQuery and preserve derived related-term behavior.

### Task 4: MCP/Admin boundary
- [ ] Add descriptor/schema tests for semantic and lexical inventory, preview and governed apply.
- [ ] Expose only bounded Governance adapters; reject arbitrary predicates/endpoints/private Evidence.

### Task 5: Verification
- [ ] Run focused, Phase 1, Dictionary, Graph, Governance, Dossier, MCP, migration and relevant frontend tests.
- [ ] Run PHP lint, git diff --check and the full Unit suite with repository memory.
- [ ] Update V3_EXECUTION_STATE.md and commit only after focused gates pass as feat(dictionary): add governed semantic enrichment projection.
