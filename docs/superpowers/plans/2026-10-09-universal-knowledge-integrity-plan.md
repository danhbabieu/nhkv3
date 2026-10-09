# Universal Knowledge Integrity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Prevent operational instructions, source locators, metadata and unverified inference from entering Knowledge while preserving valid explicit facts, governed recovery and truthful public-readiness boundaries.

**Architecture:** Extend the existing shared semantic interpreter with ephemeral input classification and controlled facet inference. Keep Capture recovery, Governance, Knowledge repair and public projection as existing owners; no new canonical vocabulary, writer, schema or mutation path is introduced.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress plugin runtime, Markdown contracts.

**Spec:** `docs/superpowers/specs/2026-10-09-universal-semantic-recovery-documentation-alignment-design.md`

## Global Constraints

- `nhk.capture.ingest` remains the only normal new-submission boundary.
- WordPress `wp_posts` remains editorial truth; Knowledge, Source/Evidence, Graph, Media and Video retain their owners.
- Source URLs are locators, not Knowledge claims or Evidence by themselves.
- Pending or missing Source/Evidence remains explicit review/dependency state.
- No staging/production mutation, migration, seed, backfill, deployment or push is authorized by this plan.
- Westminster is a fixture/example only; shared code contains no Westminster-specific branch.

## Review Focus

- A source-only line must not become a claim; test in the shared interpreter and text adapter.
- An explicit factual assertion must remain admissible; test alongside source and instruction text.
- Unverified inference must remain review-gated; test candidate provenance/status and guard behavior.
- Facet inference must use registered facets and preserve explicit caller scope; test chronology, recognition, configuration, music and fallback behavior.
- Existing recovery and completion rules must remain unchanged; run focused recovery/completion suites and compare full-suite baseline.

### Task 1: Lock universal input classification regressions

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/TextInputInterpreterRegressionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/StructuredSemanticInterpreterTest.php`

- [x] Add failing tests for source-only, mixed source/fact, media metadata and unverified inference input.
- [x] Run the focused tests and confirm the current implementation fails for the source-only and inference cases.
- [x] Add facet assertions for chronology, recognition and music/configuration cues without fixture-specific names.

### Task 2: Implement shared classification and facet inference

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredSemanticInterpreter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/TextInputInterpreter.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredInterpretationPacket.php` only if a transient packet default is required.

- [x] Classify source locators, operational/editorial instructions and media metadata before claim fallback.
- [x] Preserve source locator and metadata signals in ephemeral source/non-semantic context only.
- [x] Mark unverified inference as derived/review-required so `SemanticClaimCandidateGuard` rejects it.
- [x] Infer only existing registered facets; retain explicit facet/scope and use `identity` only as the unresolved fallback.
- [x] Run the focused tests until green.

### Task 3: Align active contracts and status evidence

**Files:**
- Modify: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

- [x] Document the classification examples and the source-locator/evidence boundary in the owning contracts.
- [x] Record that existing recovery, audit/repair preview and completion-obligation code was verified locally.
- [x] Record the missing authorized TEST runtime and Westminster public acceptance as blockers, without claiming deployment or mutation.

### Task 4: Verify and report

**Files:**
- No new runtime files.

- [x] Run focused semantic/recovery/governance/completion tests.
- [x] Run relevant full Unit and Contract suites, PHP lint, `git diff --check`, and scoped secret review.
- [x] Inspect the final diff and preserve unrelated pre-existing worktree changes.
- [x] Report root causes, invalid-data classification, code/documentation changes, repair/governance boundaries, capture recovery, test results, commit identity and blockers.
