# Universal Lexical Acquisition Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans (recommended for this session) to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the shared read-only lexical acquisition path with explicit qualification states, evidence-aware overlap handling, provenance-safe cross-source aggregation and an independent holdout evaluation set.

**Architecture:** Refine the existing `DictionaryTermDetector`, lexical quality gate, shared interpreter, seed planner and corpus audit coordinator. Keep the current resolver and persistence owners unchanged unless a failing contract test proves a boundary defect. Add holdout fixtures and a bounded evaluator beside existing regression sentinels; all audit paths remain read-only.

**Tech Stack:** PHP 8.x, WordPress plugin, PHPUnit, existing Dictionary/Semantic DTOs and corpus readers, PHP lint, `git diff --check`.

**Spec:** `docs/superpowers/specs/2026-10-01-universal-lexical-acquisition-design.md`

## Global Constraints

- No Article-, Knowledge-, Video- or Media-specific lexical pipeline.
- No new Dictionary owner, Authority type, Graph predicate, schema or persistence boundary.
- No resolver redesign without a failing test proving an existing resolver boundary is incorrect.
- No Knowledge, Authority, Graph, Evidence, Dictionary, Article or WordPress writes; no migration, seed, backfill, staging operation or deployment.
- No full Article or Knowledge corpus scan during development.
- `QUALIFIED` spans alone may create semantic query seeds or resolver lookups.
- `OBSERVATION_ONLY` spans remain reviewable with provenance and never consume resolver budget.
- Repeated occurrences in one source do not count as independent corroboration.
- Every audit result preserves `read_only=true` and `mutated=false`.
- Diagnostics are bounded and must not expose source bodies, private text, credentials or raw resolver failures.

## Review Focus

- A valid unknown single-word term must survive without an approved label, while a grammatical single word in weak context remains observation-only or noise; test in `DictionaryTermDetectorTest`.
- A stronger proper-name, identifier or configuration span must beat a longer generic prose span without splitting into unsupported fragments; test in `DictionaryTermDetectorTest`.
- An observation-only term must remain in the packet/audit evidence but never appear in `semantic_query_seeds` or resolver calls; test in `StructuredSemanticInterpreterTest` and `DictionarySeedPlannerTest`.
- Two occurrences from one source and a derived copy must not equal two independent sources; test in `DictionarySeedCorpusAuditTest`.
- A cursor split at the lookup budget must return every qualified seed exactly once and preserve source/provenance aggregation; test in `DictionarySeedCorpusAuditTest`.

## File Map

- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLexicalQualityGate.php` — expose generic contextual evidence decisions without growing a domain blacklist.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php` — separate discovery, evidence classification and overlap arbitration while preserving current detector output compatibility.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredSemanticInterpreter.php` — retain observation-only spans and emit only qualified query seeds.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedPlanner.php` — enforce resolver eligibility and preserve source/provenance aggregation fields.
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedCorpusAuditCoordinator.php` — aggregate source-local observations safely and keep cursor/progress deterministic.
- Create: `public/wp-content/plugins/nhk-core/tests/Fixtures/DictionaryLexicalHoldout.php` — independent labeled cases; use the repository's existing fixture convention if the exact fixture path differs.
- Create or modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryLexicalHoldoutTest.php` — evaluate labeled holdout cases and report supported metrics only.
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryTermDetectorTest.php` — new contextual discovery and overlap tests.
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/StructuredSemanticInterpreterTest.php` — observation/seed boundary tests.
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedPlannerTest.php` — resolver eligibility and provenance-field tests.
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedCorpusAuditTest.php` — cross-source, derived-lineage and cursor tests.
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` — final checkpoint evidence only after implementation and verification.

### Task 1: Lock the generic lexical evidence contract with failing tests

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryTermDetectorTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/StructuredSemanticInterpreterTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedPlannerTest.php`
- Modify only after RED: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLexicalQualityGate.php`
- Modify only after RED: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php`

**Interfaces:**
- Consumes: current detector API, lexical gate API, approved labels, hints and shared packet shape.
- Produces: explicit `QUALIFIED`/`OBSERVATION_ONLY`/noise classification, resolver eligibility and evidence-preserving span metadata without changing source adapters.

- [ ] **Step 1: Write failing detector tests** for an unknown valid single-word term, a weak-context function word, an identifier/proper-name boundary, an overlapping long prose span versus a stronger shorter span, and an adjacent approved-label case.
- [ ] **Step 2: Run the focused detector tests and verify RED** because the current implementation either drops the unknown single word, qualifies weak context, or selects the wrong overlap.
- [ ] **Step 3: Write failing interpreter/planner tests** proving observation-only spans remain visible in lexical observations but do not become query seeds or resolver calls.
- [ ] **Step 4: Run those tests and verify RED** with an expected failure on the current seed/eligibility boundary.
- [ ] **Step 5: Implement the smallest gate/detector changes** so segmentation, discovery, evidence assessment and overlap arbitration are explicit through existing arrays/DTO fields; keep function-word lists as contextual signals and do not add domain phrases.
- [ ] **Step 6: Implement the smallest interpreter/planner eligibility change** so only `QUALIFIED` spans enter query seeds and resolver lookup while `OBSERVATION_ONLY` spans retain bounded metadata.
- [ ] **Step 7: Run detector, interpreter and planner tests and verify GREEN**, then run PHP lint on changed PHP files.
- [ ] **Step 8: Commit** with `git add` limited to the gate/detector/interpreter/planner files and focused tests, then `git commit -m "feat: formalize lexical evidence states"`.

### Task 2: Preserve provenance and distinguish source-local frequency from independent sources

**Files:**
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedPlannerTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionarySeedCorpusAuditTest.php`
- Modify only after RED: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedPlanner.php`
- Modify only after RED: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedCorpusAuditCoordinator.php`

**Interfaces:**
- Consumes: qualified seed packet, source context, lineage and existing cursor/audit DTOs.
- Produces: normalized-term aggregation with source-local occurrences, independent-source count, raw forms, source identifiers/families, derived lineage and bounded diagnostics; no persistence.

- [ ] **Step 1: Write failing planner tests** for two raw forms of one normalized term, two source identifiers in one aggregate, repeated same-source replay, and a derived copy that must not increment independent-source count.
- [ ] **Step 2: Run the focused planner/audit tests and verify RED** because the current planner collapses source context to one `source_family` per plan and does not expose every required distinction.
- [ ] **Step 3: Write failing cursor tests** for a lookup budget split across multiple batches, asserting no lost/duplicate normalized seed and deterministic next cursor state.
- [ ] **Step 4: Run the cursor tests and verify RED** with the current aggregation/cursor behavior.
- [ ] **Step 5: Implement source-aware aggregation** using existing ephemeral/audit structures; count occurrences within a source separately from independent source identities and retain derived lineage without treating it as corroboration.
- [ ] **Step 6: Implement cursor-safe continuation** so pagination advances through all qualified seeds, preserves aggregate fields and rejects changed/missing source state according to existing fail-closed cursor semantics.
- [ ] **Step 7: Run focused planner/audit tests and verify GREEN**, then run PHP lint and `git diff --check`.
- [ ] **Step 8: Commit** with `git commit -m "fix: preserve lexical provenance across audit batches"`.

### Task 3: Add the independent gold-labeled holdout evaluator

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Fixtures/DictionaryLexicalHoldout.php` or the repository's existing equivalent fixture location.
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryLexicalHoldoutTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryTermDetectorTest.php` only for shared helpers if required.

**Interfaces:**
- Consumes: the public detector/interpreter output and immutable labeled fixture cases.
- Produces: deterministic test failures for boundary/state mismatches and a bounded metric summary that reports only metrics supported by fixture labels.

- [ ] **Step 1: Add labeled holdout fixtures** covering technical/history, narrative, advertising, dialogue/ASR, Vietnamese and foreign names, unknown compounds, overlap, identifiers/configurations, ambiguity, weak context and derived/repeated sources.
- [ ] **Step 2: Write the evaluator tests first** asserting expected spans, lexical state, resolver eligibility, name/identifier preservation and provenance expectations per case.
- [ ] **Step 3: Run the holdout suite and verify RED** on at least one newly introduced independent case, not only on Article 18/19/41 samples.
- [ ] **Step 4: Implement only the minimal fixture-driven corrections** in the existing detector/interpreter boundaries; do not add fixture-specific terms or exceptions.
- [ ] **Step 5: Run the holdout suite and verify GREEN**, recording precision/recall/boundary/false-positive/noise metrics only where gold labels are complete; report unsupported metrics as unavailable.
- [ ] **Step 6: Commit** with `git commit -m "test: add independent lexical holdout"`.

### Task 4: Run regression sentinels and complete read-only verification

**Files:**
- Test: existing targeted Dictionary/Semantic suites under `public/wp-content/plugins/nhk-core/tests/Unit/`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Consumes: completed lexical implementation, regression fixtures, holdout report and existing audit flags.
- Produces: verified local checkpoint with explicit limitations, no-mutation evidence and no deployment claim.

- [ ] **Step 1: Run targeted regression tests** for Article 18, 19 and 41, the escape/compound/name/identifier/ambiguity cases, observation-only exclusion, and seed cursor continuation.
- [ ] **Step 2: Run the independent holdout evaluator** and capture exact counts/metrics plus any incomplete-label limitations.
- [ ] **Step 3: Run the relevant targeted PHPUnit command**, PHP lint on every changed PHP file, `git diff --check`, and the repository secret-review command used by prior checkpoints.
- [ ] **Step 4: Inspect the final diff** for invented entity types, predicates, writers, source-body leakage, resolver-budget increases or accidental mutation paths.
- [ ] **Step 5: Update `V3_EXECUTION_STATE.md`** with root causes confirmed, files changed, exact test results, open uncertainty, `read_only=true`, `mutated=false`, `NO_DATA_MUTATION` and `DEPLOYMENT_NOT_PERFORMED`.
- [ ] **Step 6: Review `git status` and commit** the execution evidence with `git commit -m "docs: record universal lexical acquisition checkpoint"`.

## Final Verification Commands

Run from `/Users/imac24-2125d/Developer/nhk-v3`:

```bash
vendor/bin/phpunit --filter 'DictionaryTermDetectorTest|StructuredSemanticInterpreterTest|DictionarySeedPlannerTest|DictionarySeedCorpusAuditTest|DictionaryLexicalHoldoutTest' public/wp-content/plugins/nhk-core/tests/Unit
php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryLexicalQualityGate.php
php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryTermDetector.php
php -l public/wp-content/plugins/nhk-core/src/Application/Semantic/StructuredSemanticInterpreter.php
php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedPlanner.php
php -l public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionarySeedCorpusAuditCoordinator.php
git diff --check
```

The full project PHPUnit suite is not required during development unless the
changed boundaries reveal broader regressions. If run and failures remain,
report each by name and distinguish pre-existing failures from regressions.

