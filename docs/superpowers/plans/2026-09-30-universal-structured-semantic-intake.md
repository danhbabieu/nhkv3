# Universal Structured Semantic Intake & Synthesis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. This is a documentation/architecture-only plan; do not add runtime implementation.

**Goal:** Establish the Universal Structured Semantic Intake & Synthesis Law as constitutional law plus one canonical subordinate contract, with discoverable cross-references and an explicit documented-versus-runtime status boundary.

**Architecture:** Add one constitutional amendment and one authoritative subordinate contract. Existing Article, Knowledge, Living Knowledge and Dictionary contracts will point to the shared contract instead of duplicating its rules. The canonical documentation allowlist will expose the new ACTIVE contract through the existing read-only documentation registry, without changing MCP handlers, schemas or transport behavior.

**Tech Stack:** Markdown documentation, existing canonical documentation registry, PHPUnit documentation-registry tests, repository text/reference checks.

**Spec:** `docs/superpowers/specs/2026-09-30-universal-structured-semantic-intake-design.md`

## Global Constraints

- `nhk.capture.ingest` remains the canonical entry point for new submissions.
- The structured interpretation packet is ephemeral and read/planning-only.
- No new Authority type, Graph predicate, endpoint, Knowledge store, Dictionary owner, Article semantic owner or write bypass.
- No schema, migration, backfill, data mutation, deployment, URL change or ownership change.
- Production/runtime data is regression evidence, never implementation vocabulary or authorization.
- Documentation must distinguish `LAW APPROVED / DOCUMENTED` from `RUNTIME IMPLEMENTED`.
- Generated prose, transcript text, OCR, captions, SEO copy and derived copies never become automatic Knowledge or Evidence.
- Ambiguity, unsupported identity, invalid scope or insufficient provenance/evidence fail closed without discarding valid lexical observation.

## Review Focus

- Existing Capture/reconciliation law must be consolidated, not contradicted — verify the amendment cross-references §20.1 and Universal Capture without creating a second entry point.
- The new packet must remain non-persistent and non-semantic — verify the contract explicitly excludes canonical identity, Graph endpoints, Source/Evidence ownership and raw Article-body storage.
- ACTIVE documentation must be discoverable through the same manifest — verify the registry path/key and deterministic manifest behavior.
- Transcript and generated-output lineage must prevent false corroboration — verify the contract separates source lineage, derived lineage and Evidence.
- Documentation must not claim a complete runtime interpreter — verify status-index wording and contract status use explicit implementation gaps.

### Task 1: Add the constitutional interpretation law

**Files:**
- Modify: `docs/constitution/NHK_V3_CONSTITUTION.md` near the existing Universal Capture/reconciliation amendments and the normative law/checklist sections.
- Test: existing documentation/reference checks; no runtime test change.

**Interfaces:**
- Consumes: Existing Capture, Content Intent, §20.1 reconciliation, Knowledge, Dictionary, Graph and generated-copy laws.
- Produces: One constitutional amendment and concise checklist/reference language requiring structured interpretation before semantic resolution, enrichment, mutation planning or synthesis.

- [ ] **Step 1: Write the amendment text**

  Add a dated amendment that defines the canonical sequence `RAW INPUT → INTERPRET → STRUCTURED SPANS/CANDIDATES → RESOLVE → REUSE → EVALUATE → DELTA PLANNING → GOVERNANCE IF MUTATION → SYNTHESIS/PROJECTION IF READ PATH`.

- [ ] **Step 2: Encode the constitutional barriers**

  State that `RAW TEXT → DIRECT SEMANTIC WRITE` and `GENERATED TEXT → AUTOMATIC KNOWLEDGE` are forbidden, and retain `nhk.capture.ingest` as the only normal new-submission entry point.

- [ ] **Step 3: Consolidate existing invariants**

  Cross-reference rather than duplicate existing clauses, while making the required distinctions explicit: detection/truth, resolution/relation, relation/fact, mention/evidence, lexical identity, Graph applicability, confidence/approval, unknown/false, ambiguity/fail-closed, narrow scope and reuse-before-create.

- [ ] **Step 4: Verify the amendment**

  Run `git diff --check` and inspect nearby constitutional amendments for contradictory wording, duplicate law or accidental permission to mutate.

### Task 2: Create the canonical subordinate contract

**Files:**
- Create: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`.
- Test: repository Markdown/reference consistency checks.

**Interfaces:**
- Consumes: `docs/superpowers/specs/2026-09-30-universal-structured-semantic-intake-design.md` and the constitutional amendment from Task 1.
- Produces: The single canonical contract for the ephemeral `StructuredInterpretationPacket` concept and shared intake/synthesis rules.

- [ ] **Step 1: Define ownership and packet boundary**

  Document the packet as an ephemeral planning/result object with source context, raw-input lineage, locale, intent, lexical/semantic/trust signals, candidate arrays, reuse matches, delta candidates and diagnostics; explicitly state that it is not a database entity or semantic owner.

- [ ] **Step 2: Define the three interpretation layers**

  Separate Language, Semantic Structure and Trust/Scope. State that lexical detection cannot decide semantic identity or trust, and that packet fields are candidates/signals until existing owners and contracts validate them.

- [ ] **Step 3: Define shared lexical and structural rules**

  Include longest reusable span, clause-boundary trimming, structural-unit eligibility, identifier morphology, proper-name continuation, stronger-span overlap/dedupe, contextual numeric designation and ambiguity behavior.

- [ ] **Step 4: Define transcript and lineage rules**

  Cover spoken shorthand, ASR/transcription uncertainty, human correction, source lineage, derived lineage and the prohibition on treating transcript or generated output as automatic Evidence.

- [ ] **Step 5: Define enrichment and delta order**

  Specify resolve → Dictionary → canonical search → bounded Graph discovery → Knowledge search → reuse analysis → Source/Evidence validation → actual-gap determination, with delta classes described as planning outcomes only and not storage enums.

- [ ] **Step 6: Define Knowledge, Dictionary and Relation boundaries**

  Require claim atomization, reuse-before-create, Dictionary-as-lexical-curation, registered predicates, resolved endpoints, scope/provenance/evidence validation and Governance for mutation.

- [ ] **Step 7: Define synthesis and feedback-contamination law**

  Require applicable Knowledge selection before synthesis, body-free claim trace where existing contracts require it, and lineage that prevents generated/derived copies from inflating frequency, corroboration or canonical truth.

- [ ] **Step 8: Define implementation status and future acceptance**

  Mark the contract as `LAW APPROVED / DOCUMENTED` only; identify Dictionary and Capture/Living Knowledge as partial implementation slices; list future lexical, semantic, trust and synthesis acceptance categories without claiming they are implemented now.

- [ ] **Step 9: Verify the contract**

  Search for placeholders (`TODO`, `TBD`, `FIXME`), inspect all cross-references, and run `git diff --check`.

### Task 3: Add concise domain cross-references and status routing

**Files:**
- Modify: `docs/constitution/READ_FIRST.md` in the authoritative contract map.
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md` with current-law routing and implementation status.
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md` with a shared interpretation reference at its required stage order.
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md` with atomization, reuse and generated-text lineage references.
- Modify: `docs/architecture/DICTIONARY_LEXICAL_KNOWLEDGE_CONTRACT.md` with shared lexical-boundary and packet references.
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md` with shared interpretation/reuse/trust references.
- Test: existing documentation contract tests and repository reference checks.

**Interfaces:**
- Consumes: Canonical contract from Task 2.
- Produces: One-way references from domain contracts to the shared law; no duplicated universal policy.

- [ ] **Step 1: Route the contract from READ_FIRST**

  Add the new contract to the Dictionary/Article/Knowledge relevant-document map only once, preserving the existing Constitution-first precedence.

- [ ] **Step 2: Update the status index**

  Record the contract as current canonical documentation and explicitly state that the universal packet/interpreter is not fully implemented.

- [ ] **Step 3: Add domain references**

  Add short references at the existing stage/ownership sections; do not copy the shared invariant list or create domain-specific variants.

- [ ] **Step 4: Verify reference integrity**

  Run `rg` checks for the new path, confirm no broken relative links, and run the relevant documentation contract tests.

### Task 4: Register the contract in the existing documentation manifest only

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php` by adding one `canonical_contract`/`ACTIVE` documentation definition for the new path, if the existing allowlist requires it for ACTIVE canonical contracts.
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php` and related documentation contract tests; do not change MCP handlers, schemas, transport or runtime capabilities.

**Interfaces:**
- Consumes: Contract path from Task 2 and status/router references from Task 3.
- Produces: Read-only manifest discoverability for the canonical contract, with no new operation or semantic capability.

- [ ] **Step 1: Confirm allowlist necessity**

  Use `McpDocumentationRegistry::documentDefinitions()` and existing manifest tests to confirm the new ACTIVE contract would otherwise be unavailable through canonical documentation read.

- [ ] **Step 2: Add metadata-only registration**

  Add one path/classification/status/domain entry in the existing `DOCUMENTS` map. Do not alter handlers, tool schemas, checkpoint semantics or runtime behavior.

- [ ] **Step 3: Verify deterministic documentation projection**

  Run `vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php` and the relevant documentation contract tests; verify the new path is present and manifest hashing remains deterministic.

### Task 5: Final documentation-only verification and commit

**Files:**
- Verify all files from Tasks 1–4.

**Interfaces:**
- Consumes: Completed amendment, contract, cross-references and optional metadata-only allowlist row.
- Produces: One reviewable documentation-law checkpoint with no runtime/data mutation.

- [ ] **Step 1: Run reference and placeholder checks**

  Confirm all references resolve, no `TODO`/`TBD`/`FIXME` remains in changed documentation, and the new contract is present in the canonical allowlist when required.

- [ ] **Step 2: Run documentation tests**

  Run the focused documentation registry/contract tests and any repository Markdown consistency check available; record exact counts and unrelated baseline failures separately.

- [ ] **Step 3: Run safety checks**

  Run `git diff --check` and a secret review. Confirm no schema/migration/data/runtime/MCP/Graph/Authority changes in the diff.

- [ ] **Step 4: Commit the documentation checkpoint**

  Commit only the Constitution amendment, canonical contract, cross-references/status routing and metadata-only documentation allowlist row if required, using a documentation-law commit message. Do not deploy.

