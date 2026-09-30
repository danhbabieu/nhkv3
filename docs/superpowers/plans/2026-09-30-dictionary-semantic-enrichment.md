# Dictionary Semantic Enrichment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the shared structured semantic intake, read-only Dictionary Seed v1 planning, and bounded semantic enrichment seam without creating semantic truth or mutating data.

**Architecture:** Extend the existing ephemeral `UniversalInputEnvelope` and `StructuredInterpretationPacket`; keep `StructuredSemanticInterpreter` as the only lexical/structural core. Dictionary and enrichment remain external consumers that resolve and retrieve through existing repositories/services, while an optional MCP adapter exposes only privacy-safe read-only planning output.

**Tech Stack:** PHP 8.x, PHPUnit, existing NHK V3 application/domain/contracts, WordPress plugin composition root, MCP catalog/dispatch/Ability patterns.

**Spec:** `docs/superpowers/specs/2026-09-30-dictionary-semantic-enrichment-design.md`

## Global Constraints

- `nhk.capture.ingest` remains the only normal new-submission entry point.
- The packet is ephemeral and never becomes a database entity or canonical owner.
- `LEXICAL MATCH ≠ SEMANTIC IDENTITY`, `GRAPH REACHABILITY ≠ APPLICABILITY`, `FREQUENCY ≠ AUTHORITY`, and `GENERATED PROSE ≠ EVIDENCE`.
- Search, resolve and reuse precede any unresolved candidate; ambiguity fails closed; unknown valid lexical terms remain visible to planning.
- No schema, migration, seed, backfill, live/staging semantic mutation, direct writer, Governance bypass or production ingestion adapter.
- Full-corpus verification is claimed only when the canonical WordPress/MySQL runtime is available.

## Review Focus

- Generic unseen multi-token phrases must survive while clause tails are trimmed — pin with synthetic detector tests in Task 1.
- A number/unit phrase must fail closed when a unit has no independent lexical eligibility — pin with structural configuration tests in Task 1.
- The same text across source kinds must yield compatible lexical/query-seed output while lineage differs — pin with adapter-equivalence tests in Task 1.
- A resolved Dictionary term must not be auto-linked when the resolver returns multiple owners — pin with Dictionary planner tests in Task 2.
- A Graph-reachable Knowledge claim with wrong scope or missing evidence must not enter enrichment output — pin with retrieval seam tests in Task 3.

### Task 1: Complete the shared input, packet and generic lexical core

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/UniversalInputEnvelope.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredInterpretationPacket.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredSemanticInterpreter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLexicalQualityGate.php` only if the existing generic gate needs a narrow reusable boundary
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StructuredSemanticInterpreterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryTermDetectorTest.php`
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/UniversalStructuredSemanticAdapterParityTest.php`

**Interfaces:**
- Consumes: existing `UniversalInputEnvelope::fromArray(array): self` and `StructuredSemanticInterpreter::interpret(UniversalInputEnvelope|array): StructuredInterpretationPacket`.
- Produces: normalized input fields for `text`, `locale`, `source_kind`, source reference, raw/derived lineage, content intent, canonical target hint, provenance context, observation strength and bounded hints; packet field `semantic_query_seeds` with raw span, normalized form, category, locale, context, optional resolved reference, facet hint, ambiguity and diagnostics.

- [ ] **Step 1: Write failing tests** for input normalization, packet defaults/query-seed shape, source-kind parity across `human_chat`, `article`, `video_transcript` and `media_caption`, editorial signals, unknown valid terms, proper names, identifiers, valid/invalid configurations, clause-tail trimming, ambiguity and derived lineage.
- [ ] **Step 2: Run the focused tests and verify they fail** using `vendor/bin/phpunit --filter 'StructuredSemanticInterpreterTest|DictionaryTermDetectorTest|UniversalStructuredSemanticAdapterParityTest'` from `public/wp-content/plugins/nhk-core`.
- [ ] **Step 3: Implement the minimal shared contract extension** while preserving aliases used by current Capture/Article consumers; keep all fields ephemeral and bounded. Generate query seeds from selected lexical spans without performing repository lookup or Governance work.
- [ ] **Step 4: Replace only domain-specific detector assumptions that violate the active generic lexical contract** with reusable lexical/structural eligibility and longest/strongest-span rules. Do not add fixture terms or a second detector.
- [ ] **Step 5: Run the focused tests and verify they pass**, then run the existing `TextInputInterpreter`, `UniversalEnrichmentCore`, `SharedEnrichmentBoundary` and Article research tests to confirm compatibility.
- [ ] **Step 6: Run `git diff --check` and PHP lint on every changed PHP file.**
- [ ] **Step 7: Commit** the shared core and lexical test slice with a focused message.

### Task 2: Make Dictionary planning consume the packet and add Dictionary Seed v1

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPlanningService.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedPlanner.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedResult.php` if the existing result vocabulary has no suitable ephemeral representation
- Inspect/reuse: `public/wp-content/plugins/nhk-core/src/Domain/Dictionary/DictionaryCandidateState.php`, `DictionaryResolution.php`, and existing Dictionary repositories
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPlanningServiceTest.php`
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedPlannerTest.php`

**Interfaces:**
- Consumes: `StructuredInterpretationPacket`, `DictionaryResolver`, existing candidate/mention repositories and bounded Dictionary context.
- Produces: a read-only seed plan with deterministic normalized deduplication, raw observed forms, source-family/occurrence lineage, resolution class, ambiguity, suppression and planning diagnostics; `DictionaryPlanningService` continues to expose existing preview/plan compatibility behavior.

- [ ] **Step 1: Write failing tests** for approved-label reuse, alias-to-existing concept, canonical owner preference, true ambiguity, unresolved valid candidate, proper-name/identifier/configuration classification, editorial/noise/suppressed handling, duplicate normalized observations, source-family lineage and zero writes in preview/audit paths.
- [ ] **Step 2: Run the focused Dictionary tests and verify the new tests fail** without changing existing mutation tests.
- [ ] **Step 3: Refactor `DictionaryPlanningService` to use the packet as its only lexical input**; remove any direct parser/detector path outside the shared interpreter while preserving mention/candidate persistence only for the existing explicit `plan()` mutation-compatible path.
- [ ] **Step 4: Implement `DictionarySeedPlanner::plan(StructuredInterpretationPacket|array, array $options = []): array`** as an ephemeral read-only planner. Deduplicate by normalized term plus bounded context, retain raw forms and source families, call existing resolution/search services first, and map unresolved/ambiguous/suppressed/editorial/noise outcomes without inventing persistent taxonomy.
- [ ] **Step 5: Add a zero-write spy test** proving the seed planner never calls candidate upsert, mention upsert, concept mutation, Knowledge mutation or Graph mutation.
- [ ] **Step 6: Run the focused Dictionary suite plus existing resolver, harvester, replay, mutation-contract and public-query tests.**
- [ ] **Step 7: Run PHP lint and `git diff --check`, then commit** the Dictionary consumer/planner slice.

### Task 3: Add the external semantic retrieval/enrichment seam

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticEnrichmentPlanner.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SharedEnrichmentBoundary.php` only for a narrow adapter seam, if compatibility wiring requires it
- Reuse: `public/wp-content/plugins/nhk-core/src/Application/Semantic/EditorialClaimRetrievalService.php`, `EditorialKnowledgeSelector.php`, `KnowledgeEnrichmentPlanner.php`, `DerivedLineageGuard.php`, existing Graph read services and registered endpoint/predicate registries
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/SemanticEnrichmentPlannerTest.php`
- Test: modify `public/wp-content/plugins/nhk-core/tests/Unit/SharedEnrichmentBoundaryTest.php` only for compatibility assertions

**Interfaces:**
- Consumes: `StructuredInterpretationPacket`, Dictionary seed output, existing canonical read/retrieval services and bounded Graph/Knowledge results.
- Produces: read-only enrichment result containing query seeds, Dictionary resolution, canonical owner matches, bounded Graph candidates, applicable Knowledge claims, rejected claims with scope/provenance/evidence diagnostics, lineage and `mutated=false`.

- [ ] **Step 1: Write failing tests** for the sequence `query seeds → Dictionary → canonical owner → bounded Graph → Knowledge → validation`, including exact reuse, wrong scope rejection, missing evidence rejection, Graph-reachable-but-inapplicable rejection, ambiguity fail-closed and unavailable-runtime distinction.
- [ ] **Step 2: Run the new seam tests and verify they fail** before adding orchestration.
- [ ] **Step 3: Implement the external planner** as a composition service; it may call existing read services but must not add retrieval logic to `StructuredSemanticInterpreter` and must not call Governance or write repositories.
- [ ] **Step 4: Apply `DerivedLineageGuard` to prevent generated/derived prose from counting as independent corroboration**, while retaining it as an editorial/lexical diagnostic input.
- [ ] **Step 5: Add tests proving claims remain bounded to subject, facet/scope, provenance and evidence and that a valid unknown lexical term is not discarded merely because semantic resolution is unavailable.**
- [ ] **Step 6: Run semantic retrieval, enrichment, knowledge planner, lineage and Article/Video/Media shared-boundary tests; run PHP lint and diff checks.**
- [ ] **Step 7: Commit** the read-only enrichment seam.

### Task 4: Expose Dictionary Seed v1 through the existing read-only operator pattern

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Mcp/DictionarySeedAuditHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpToolCatalog.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDispatchRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpAbilityRegistration.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpCapabilityManifest.php` and `SingleEntryPointPolicy.php` only where parity requires the existing internal/admin read-only exposure
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` for dependency wiring, following the Knowledge quality-audit composition pattern
- Test: create `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedAuditMcpTest.php`

**Interfaces:**
- Consumes: `DictionarySeedPlanner` and bounded packet/source input; follows the exact catalog → dispatch → transport → Ability parity used by `nhk.knowledge.quality-audit`.
- Produces: a privacy-safe, paginated/filterable read-only operation using the final registry-conforming name; no approval, attach, create, Knowledge write or Graph mutation capability.

The operation name is `nhk.dictionary.seed-audit`; its Ability name is
`nhk-v3/dictionary-seed-audit`, and it uses the same internal/admin read-only
capability boundary as the Knowledge quality audit.

- [ ] **Step 1: Write failing catalog/dispatch/transport/Ability parity tests** for operation discovery, internal/admin capability gating, public rejection, bounded pagination/filtering, privacy-safe output and explicit `mutated=false`.
- [ ] **Step 2: Run the MCP test and verify it fails** because the operation is not registered.
- [ ] **Step 3: Implement the handler and wire the operation** through the existing read-only internal/admin pattern; keep it outside the public operator allowlist unless the established quality-audit contract explicitly requires otherwise.
- [ ] **Step 4: Add zero-write and unavailable-runtime tests**; unavailable storage must not be reported as an empty successful corpus.
- [ ] **Step 5: Run Dictionary MCP, quality-audit MCP, MCP contract, transport and capability tests.**
- [ ] **Step 6: Run PHP lint and `git diff --check`, then commit** the operator surface.

### Task 5: Update contracts, inheritance documentation and execution state

**Files:**
- Modify: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- Modify: `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Create only if implementation needs cross-domain mapping: `docs/architecture/SEMANTIC_ENRICHMENT_INHERITANCE_PLAN.md`
- Test/verification: relevant contract tests and documentation manifest/checks

- [ ] **Step 1: Update the universal contract** with the final input/output seam, query-seed semantics, adapter inheritance and external-consumer boundary; preserve Constitution precedence and implementation-partial status until verification is complete.
- [ ] **Step 2: Update the Dictionary contract** with Dictionary as first packet consumer, lookup/reuse order, Dictionary Seed v1 read-only behavior and no-auto-approval rules.
- [ ] **Step 3: Add the non-normative inheritance plan only if needed** to map Chat, Article, Video, Media and legacy Knowledge cleanup onto the same core without creating parallel architecture law.
- [ ] **Step 4: Run the focused suites, PHP lint, `git diff --check`, secret review and documentation/runtime checks.**
- [ ] **Step 5: Read the current execution state again and append a checkpoint** that reports exact implemented slices, tests, zero-write status and any environment-gated corpus/runtime verification; never claim the 77 Article/1,170 Knowledge scan unless available.
- [ ] **Step 6: Commit** the documentation and execution-state checkpoint.

### Task 6: Final verification and corpus-regression report

**Files:**
- Modify only if verification reveals a defect: files from Tasks 1–5
- Evidence: dated local test/report output; no data dump or secret-bearing artifact

- [ ] **Step 1: Run the focused core, Dictionary, enrichment and MCP suites** and record test/assertion counts.
- [ ] **Step 2: Run the relevant existing Dictionary regression corpus** and report resolved-existing, new candidates, unique candidates, ambiguous, noise/editorial fragments, identifier/configuration preservation and proper-name preservation.
- [ ] **Step 3: If the canonical WordPress/MySQL runtime is available, run the bounded read-only 77 Article/1,170 Knowledge audit; otherwise record `ENVIRONMENT_BLOCKED` without substituting fixtures or empty data.**
- [ ] **Step 4: Run full proportionate verification**: PHP lint, relevant PHPUnit suite, `git diff --check`, secret review and migration/runtime checks only if touched.
- [ ] **Step 5: Use `superpowers:verification-before-completion` before claiming completion**, then update the final execution checkpoint and commit only verified changes.
