# Universal Music Intake, Dictionary and Public Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Make the existing Music A–Z intake standard, Dictionary/Score/Audio worksheets and public-display matrix executable-parity checked without adding a semantic owner, schema, writer or mutation path.

**Architecture:** Enrich the existing read-only `MusicDataCollectionStandard` metadata contract, then author reusable Markdown worksheets and a field-to-projection matrix from that vocabulary. Reuse `UniversalInputEnvelope`/`StructuredSemanticInterpreter`, existing Dictionary and Media contracts, and `MusicDossierProjection`; register the new operating documents in the existing read-only documentation registry and record evidence in execution state.

**Tech Stack:** PHP 8.x, PHPUnit, existing NHK V3 WordPress plugin/application boundaries, Markdown canonical docs, Composer documentation snapshot generator.

**Spec:** `docs/superpowers/specs/2026-10-09-universal-music-intake-dictionary-public-display-design.md`

## Global Constraints

- `MusicDataCollectionStandard` remains read-only intake vocabulary.
- `MusicCoverageAssessment` remains a read-only diagnostic and never mutates canonical owners.
- No new Music fields in Authority storage.
- No new Dictionary/Score/Audio schema.
- No new semantic owner, endpoint, predicate or Graph store.
- Canonical changes continue through Capture → Proposal/Approval → Eligibility → Controlled Apply → canonical read-back.
- `docs/research/` files, local score JSON and local WAVs remain non-canonical.
- No migration, seed, backfill, deployment, push, staging mutation or production mutation is part of this design.
- Examples must retain `MISSING`, `UNKNOWN`, `DISPUTED`, `BLOCKED` or `CANDIDATE` when evidence/readiness is incomplete.
- No implementation or test introduces a Westminster-specific branch.
- Preserve unrelated dirty worktree changes: `OutcomeObligationCompiler.php`, its unit test, and `docs/superpowers/plans/2026-10-09-video-outcome-relation-alignment.md` are not part of this plan.

## Review Focus

- A new intake field accidentally becomes a persisted canonical field — test that metadata remains explicitly intake-only and no writer/schema/registry is changed.
- A source URL, evidence excerpt or operational/editorial instruction becomes a Knowledge candidate — test the existing interpreter classifications and non-semantic context.
- A Dictionary lexical observation or Music alias becomes a semantic owner/relation — test the worksheet boundary and resolver/reuse language.
- Score/audio with missing rights or ungoverned delivery is marked `PUBLIC_READY` — test the readiness examples and existing `MusicCoverageAssessment`/projection behavior.
- A public route or dossier leaks UUIDs, revisions, private evidence or raw media metadata — test the matrix against `MusicDossierProjection` public-safe fields.

### Task 1: Lock the executable metadata contract with RED tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`
- Test target: focused Music and documentation registry PHPUnit selections

**Interfaces:**
- Consumes: current `MusicDataCollectionStandard::fields()`, current 26-category registry, current documentation registry.
- Produces: failing assertions that define the required metadata keys and future operating-document keys.

- [ ] **Step 1: Add a failing metadata completeness test.**

  Extend the existing standard metadata test to require `uncertainty_states`,
  `duplicate_rule`, `review_rule`, `frontend_consumer`, `public_display_states`,
  `example_valid`, `example_invalid` and `example_missing` for every field;
  require non-empty values with the correct list/scalar shape; and require the
  field's `intake_only` flag to be `true`.

- [ ] **Step 2: Add a failing template/registry visibility test.**

  Add assertions for the planned document keys and paths:
  `music-contract-gap-report`, `music-input-template`,
  `dictionary-input-template`, `score-audio-input-template`,
  `music-public-display-matrix`, `documentation-changes` and
  `executable-parity-results`. Assert that each path is allowlisted and can
  be read by `McpDocumentationRegistry` after the docs exist.

- [ ] **Step 3: Run the focused tests to prove RED.**

  Run:

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php
  ```

  Expected: failure because the new metadata and document definitions do not yet exist.

### Task 2: Enrich the existing Music intake metadata without adding vocabulary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDataCollectionStandard.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php`

**Interfaces:**
- Consumes: the existing 26 categories, existing field names, existing status vocabulary and current dossier/readiness boundaries.
- Produces: `MusicDataCollectionStandard::fields()` records with complete intake-only metadata for every existing field.

- [ ] **Step 1: Add explicit metadata parameters to the existing private field builder.**

  Extend the existing builder only with metadata needed by the approved test:
  uncertainty states, duplicate/reuse rule, review rule, frontend consumer,
  public display states and valid/invalid/missing examples. Keep existing
  defaults only as temporary implementation conveniences; every field must
  receive a meaningful value before the task is complete.

- [ ] **Step 2: Populate all existing A–Z fields.**

  Add field-specific metadata for the current field keys without adding field
  keys. Use existing owners and public components only. Mark score/audio rights
  and delivery states explicitly; mark relation fields as registered-predicate
  candidates only; keep `Westminster` examples bounded and non-authorizing.

- [ ] **Step 3: Run the focused metadata test to prove GREEN.**

  Run the Task 1 PHPUnit command. Expected: PASS for all Music standard
  assertions, with no changes to the current category count or status vocabulary.

- [ ] **Step 4: Run PHP lint for the changed class.**

  Run:

  ```bash
  php -l public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDataCollectionStandard.php
  ```

  Expected: `No syntax errors detected`.

- [ ] **Step 5: Commit the executable metadata slice.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Entity/MusicDataCollectionStandard.php public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php
  git commit -m "feat: enrich music intake metadata"
  ```

### Task 3: Create reusable Music, Dictionary and Score/Audio preparation artifacts

**Files:**
- Create: `docs/architecture/MUSIC_INPUT_TEMPLATE.md`
- Create: `docs/architecture/DICTIONARY_INPUT_TEMPLATE.md`
- Create: `docs/architecture/SCORE_AUDIO_INPUT_TEMPLATE.md`
- Modify: `docs/architecture/MUSIC_DATA_COLLECTION_STANDARD.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php`

**Interfaces:**
- Consumes: executable field keys/statuses from Task 2, `UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`, Dictionary lexical contracts, Media/MediaAsset contracts and the existing Music standard.
- Produces: reusable transient worksheets that can be translated into existing Capture/interpretation workflows and never act as runtime write payloads.

- [ ] **Step 1: Add a failing document-content/parity test.**

  Assert that each template exists, declares its non-schema/transient boundary,
  contains the twelve input classes, includes all 26 category keys or links to
  the executable registry, and includes explicit `MISSING`, `UNKNOWN`,
  `DISPUTED`, `BLOCKED`, `CANDIDATE`, `VERIFIED` and `PUBLIC_READY`
  handling. Assert that the Dictionary template includes Entry/Form/Sense,
  lexical attestation and `semantic_reference`; assert that Score/Audio includes rights,
  checksum, recording/simulation classification and governed delivery.

- [ ] **Step 2: Run the document test to prove RED.**

  Run the Music coverage test. Expected: failure because the three templates
  do not yet exist.

- [ ] **Step 3: Write the Music A–Z template.**

  Include an identity-resolution preflight, field worksheet columns for every
  executable metadata property, claim/source/evidence worksheet, relationship
  worksheet, uncertainty/review worksheet, public-readiness checklist and
  missing-data report. State that input is transient and examples are not live
  data.

- [ ] **Step 4: Write the Dictionary template.**

  Include Entry/Form/Sense, locale/domain/register, lexical observation and
  attestation, owner revalidation, ambiguity, duplicate/reuse review and
  delegated-vs-dedicated public projection. State that names/aliases do not
  auto-create Dictionary Entries, Knowledge claims or Graph edges.

- [ ] **Step 5: Write the Score/Audio template.**

  Include score edition/notation witness, phrase/event metadata, arrangement and
  transposition, audio classification, provenance, duration/technical metadata,
  rights/checksum, Media/MediaAsset/MediaUsage readiness and public delivery.
  Explicitly prohibit calling a bell simulation a historical recording and
  treating `docs/research/` files as canonical assets.

- [ ] **Step 6: Align the ACTIVE Music standard document.**

  Add links/operating notes for the templates and field metadata without
  duplicating constitutional law or changing the existing A–Z table.

- [ ] **Step 7: Run the document parity test to prove GREEN.**

  Run the focused Music test and confirm the templates contain no hardcoded
  write endpoint, schema name, capability grant or mutation instruction.

### Task 4: Produce the gap report and public display matrix

**Files:**
- Create: `docs/architecture/MUSIC_AZ_CONTRACT_GAP_REPORT.md`
- Create: `docs/architecture/MUSIC_PUBLIC_DISPLAY_MATRIX.md`
- Create: `docs/architecture/DOCUMENTATION_CHANGES.md`
- Create: `docs/architecture/EXECUTABLE_PARITY_RESULTS.md`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MusicDossierProjectionTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/MusicCoverageAssessmentTest.php`

**Interfaces:**
- Consumes: Task 2 metadata, Task 3 templates, `MusicCoverageAssessment`,
  `MusicDossierProjection`, existing public route/frontend components and
  active public eligibility/SEO contracts.
- Produces: field-to-owner-to-readiness-to-frontend evidence and a typed list
  of current gaps, with no public completeness claim.

- [ ] **Step 1: Add failing public-safety assertions.**

  Extend the existing dossier projection tests to cover the matrix states:
  unavailable runtime, missing evidence, missing rights, non-public verified
  data and public-ready governed delivery. Assert that internal IDs, revisions,
  raw metadata and private Evidence remain absent.

- [ ] **Step 2: Run the projection tests to prove RED.**

  Run the focused Music dossier/projection selection. Expected: any newly
  required state assertion fails before documentation/code alignment.

- [ ] **Step 3: Write the gap report.**

  For each requested area, classify `IMPLEMENTED`, `PARTIAL`, `CODE_GAP`,
  `REGISTRY_GAP`, `DOCUMENTATION_GAP`, `RUNTIME_BLOCKED` or
  `DATA_COMPATIBILITY_GAP`; identify the controlling contract, executable
  evidence and safe next step. Record that current TEST runtime/deployed
  read-back is unavailable where applicable.

- [ ] **Step 4: Write the public display matrix.**

  Cover identity, history, structure, score, audio, citations, Dictionary
  terms, Graph relations, Media gallery, research gaps and SEO. For every row,
  distinguish canonical availability, public eligibility, rendered component,
  missing dependency and verification command/read-back.

- [ ] **Step 5: Write documentation ownership/change and parity evidence docs.**

  State which ACTIVE document owns each rule, which documents are operating
  guides, which registry entries are added, and which tests/commands verify the
  result. Keep runtime acceptance and live public Westminster acceptance as
  blockers rather than successes.

- [ ] **Step 6: Run the focused projection tests to prove GREEN.**

  Run the Music coverage and dossier tests; confirm public-safe serialization
  remains unchanged except for explicitly tested state handling.

### Task 5: Register the operating documents and regenerate the canonical snapshot

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Generated: `public/wp-content/plugins/nhk-core/resources/canonical-docs/**` through the generator only

**Interfaces:**
- Consumes: the seven operating documents from Tasks 3–4 and existing read-only
  documentation registry/snapshot behavior.
- Produces: bootstrap/list/get visibility, deterministic hashes and a current
  execution checkpoint.

- [ ] **Step 1: Add registry definitions and failing snapshot assertions.**

  Register each new document with `current_evidence` or `canonical_contract`
  classification appropriate to its content and `music`/`operator` domain.
  Extend tests to verify status, path, hash and snapshot read-back for the new
  documents.

- [ ] **Step 2: Run documentation tests to prove RED before generation.**

  Expected: failures for missing registry definitions or source files.

- [ ] **Step 3: Regenerate the snapshot.**

  Run:

  ```bash
  composer generate:mcp-docs
  ```

  Never hand-edit generated snapshot files.

- [ ] **Step 4: Run documentation registry/bootstrap tests to prove GREEN.**

  Verify that bootstrap/list/get expose the new files, source hashes match,
  traversal remains rejected, and a second generator run is deterministic.

- [ ] **Step 5: Update status index and execution state.**

  Append a dated checkpoint recording the implemented metadata/templates,
  registry/snapshot result, focused test counts, lint/diff/secret checks and
  the unchanged runtime-acceptance blockers. Read `V2_V3_PARITY_MATRIX.md`
  before making any parity statement; do not claim live Public acceptance.

- [ ] **Step 6: Commit the documentation and registry slice.**

  ```bash
  git add docs/architecture/MUSIC_DATA_COLLECTION_STANDARD.md docs/architecture/MUSIC_INPUT_TEMPLATE.md docs/architecture/DICTIONARY_INPUT_TEMPLATE.md docs/architecture/SCORE_AUDIO_INPUT_TEMPLATE.md docs/architecture/MUSIC_AZ_CONTRACT_GAP_REPORT.md docs/architecture/MUSIC_PUBLIC_DISPLAY_MATRIX.md docs/architecture/DOCUMENTATION_CHANGES.md docs/architecture/EXECUTABLE_PARITY_RESULTS.md public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDocumentationRegistry.php public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md docs/architecture/V3_EXECUTION_STATE.md public/wp-content/plugins/nhk-core/resources/canonical-docs
  git commit -m "docs: publish universal music intake artifacts"
  ```

### Task 6: Run the complete proportional verification and closeout

**Files:**
- Modify: `docs/architecture/EXECUTABLE_PARITY_RESULTS.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Consumes: all changed code/docs/tests and generated documentation snapshot.
- Produces: final evidence with exact commands, counts, baseline failures and
  remaining blockers.

- [ ] **Step 1: Run focused PHPUnit selections.**

  Run Music standard/coverage, Music dossier/projection, semantic intake and
  documentation registry tests. Record exact passing counts and any unrelated
  baseline failures separately.

- [ ] **Step 2: Run syntax and repository checks.**

  Run PHP lint for changed PHP files, JavaScript syntax checks when applicable,
  `git diff --check`, the canonical snapshot parity check and the repository's
  changed-scope secret review.

- [ ] **Step 3: Run broader unit/contract checks where available.**

  Use the repository's documented PHPUnit/contract commands with the required
  memory limit. Do not hide or downgrade failures; classify environment and
  pre-existing baselines separately.

- [ ] **Step 4: Update evidence docs and execution state.**

  Record `FILES_CHANGED`, test results, documentation bootstrap result,
  `COMMIT_SHA`, remaining blockers and the next acceptance step. Explicitly
  state that no staging/production mutation, deploy, push or live public
  Westminster acceptance occurred.

- [ ] **Step 5: Perform final worktree review.**

  Run `git status --short --branch` and `git diff --check`; verify unrelated
  pre-existing dirty files remain untouched and only task-owned paths are in
  the task commits.

## Plan self-review

- Phase 1 is covered by the gap report and parity evidence, with the required
  Constitution/ACTIVE-contract boundaries preserved.
- Phase 2 is covered by Task 2's full metadata contract for all existing A–Z
  fields.
- Phase 3 is covered by Task 3's twelve input classes and transient worksheet
  boundary.
- Phase 4 is covered by Task 3's Dictionary worksheet and Task 4's owner/gap
  report.
- Phase 5 is covered by Task 3's Score/Audio worksheet and Task 4's public
  readiness matrix.
- Phase 6 is covered by Task 4's matrix and projection tests.
- Phase 7 is covered by the three reusable templates and examples/status rules.
- Phase 8 is covered by Task 5's registry/snapshot and ownership updates.
- Phase 9 is covered by Task 1, Task 4 and Task 6 focused/regression checks.

No task authorizes live data mutation or invents a new runtime vocabulary.

