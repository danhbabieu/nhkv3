# Capture Article Endpoint Revision Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make newly-created WordPress draft Articles provide an authoritative `wp_post` endpoint revision to governed relation reconciliation without confusing it with the editorial state token.

**Architecture:** Keep WordPress `wp_posts` as the sole owner of Article endpoint identity and native modification timestamps. Keep the editorial `state_token` as a separate CAS/readback token. Harden the `wp_post` endpoint adapter so it accepts a valid GMT modification timestamp first and, for date-floating drafts whose GMT field is the WordPress zero-date sentinel, converts the native local `post_modified` field through the WordPress timezone boundary; invalid or missing timestamps still fail closed.

**Tech Stack:** PHP 8.5, PHPUnit 11, WordPress native post APIs, NHK V3 Graph endpoint registry and Governance lifecycle.

**Spec:** User-provided Capture/Governance failure request in `/Users/imac24-2125d/.codex/attachments/469ba2a9-343d-43be-9b58-601c6068ba6a/pasted-text.txt`; canonical contracts `docs/architecture/ARTICLE_INGEST_CONTRACT.md`, `docs/architecture/16_P4_GOVERNANCE_CORE_CONTRACT.md`, `docs/architecture/18_GOVERNANCE_FAILURE_AND_RETRY.md` and Constitution §§8, 14, 19, 23, 26.

## Global Constraints

- Do not use `state_token` as a semantic endpoint revision.
- Do not fabricate a default revision, use the WordPress post ID as revision, disable revision validation, bypass Governance, or special-case a live Post ID.
- Preserve native WordPress `wp_posts` ownership, Capture idempotency, relation idempotency, stale revision failure and fail-closed behavior when no authoritative timestamp exists.
- Do not mutate live/staging data in this implementation checkpoint and do not deploy or resume the live Capture from local code.
- Update canonical documentation only if the ownership distinction is not already explicit; never edit generated documentation snapshots by hand.

### Task 1: Lock the date-floating draft regression at the endpoint boundary

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RelationRevisionBindingTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/RelationRevisionBindingTest.php`

**Interfaces:**
- Consumes: `WpPostEndpointResolver::revision()` and `RelationRevisionBinder::bind()`.
- Produces: a regression proving a newly-created/date-floating draft with `post_modified_gmt=0000-00-00 00:00:00` gets a positive revision from native `post_modified`, while a missing/invalid native timestamp remains unavailable.

- [x] **Step 1: Add one failing regression test**

  Add a test named `test_binds_wp_post_revision_from_date_floating_draft_local_modified_timestamp` using a fake post with `post_modified_gmt` equal to WordPress's zero-date sentinel and a valid `post_modified` value. Register it through `WpPostEndpointResolver`, bind a relation to an existing Classification resolver, and assert `source_revision` equals the converted native modification timestamp and `target_revision` remains the target's canonical revision. Add a second focused test for an invalid/missing local timestamp that asserts the existing `Relation endpoint revision is unavailable` failure still occurs.

- [x] **Step 2: Run the focused test before changing production code**

  Run:

  ```bash
  vendor/bin/phpunit --filter RelationRevisionBindingTest --testdox
  ```

  Expected: the new date-floating draft test fails because the current resolver treats the zero-date GMT sentinel as authoritative and returns `null`; the existing tests remain green.

### Task 2: Implement the minimal authoritative WordPress timestamp normalization

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Graph/WpPostEndpointResolver.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/RelationRevisionBindingTest.php`

**Interfaces:**
- Consumes: native `WP_Post` fields `post_modified_gmt` and `post_modified`, the WordPress timezone conversion helper when available, and the existing endpoint registry identity checks.
- Produces: a positive deterministic `wp_post` endpoint revision for valid existing drafts/posts; `null` for missing/invalid timestamps.

- [x] **Step 1: Normalize the GMT field only when it is a real timestamp**

  Add a private timestamp helper that rejects empty values, `0000-00-00 00:00:00`, and non-positive `strtotime()` results. Prefer `post_modified_gmt` and return its Unix timestamp when valid.

- [x] **Step 2: Convert a valid local native modification field as the WordPress fallback**

  When the GMT field is absent or the zero-date sentinel, read `post_modified`. If the WordPress `get_gmt_from_date()` helper exists, convert the local timestamp to GMT before parsing. Otherwise use the resolver's deterministic test/runtime fallback for the supplied timestamp. Do not read `EditorialStateToken`, generate a revision, or use a default value.

- [x] **Step 3: Run the focused regression suite**

  Run:

  ```bash
  vendor/bin/phpunit --filter RelationRevisionBindingTest --testdox
  ```

  Expected: all endpoint revision tests pass, including the date-floating draft case and the fail-closed invalid-timestamp case.

### Task 3: Verify the full Capture/Governance regression surface and document ownership

**Files:**
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md` only if needed to explicitly state that `wp_post` semantic endpoint revision is the normalized native `wp_posts.post_modified_gmt` value, with date-floating drafts using the native `post_modified` fallback, and that this is distinct from the editorial `state_token`.
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` at the checkpoint after verification, following repository rules.

**Interfaces:**
- Consumes: the fixed `WpPostEndpointResolver`, `RelationRevisionBinder`, `RelationProposalReconciliationService`, Capture continuation and Governance lifecycle.
- Produces: evidence that new/existing Article relation binding, retry/resume, stale revision conflict and missing-revision fail-closed behavior remain correct.

- [x] **Step 1: Run targeted related tests**

  Run:

  ```bash
  vendor/bin/phpunit --filter 'RelationRevisionBindingTest|RelationProposalReconciliationServiceTest|ArticleIngestCoordinatorTest|CaptureArticlePreflightHandoffTest|GovernanceCoreTest|GovernanceApplyContractTest|EditorialCaptureContinuationTest|GovernedCaptureContinuationServiceTest|EditorialCaptureConvergenceE2ETest' --testdox
  ```

- [x] **Step 2: Run the plugin unit suite**

  Run the repository's configured unit suite:

  ```bash
  vendor/bin/phpunit --testsuite unit --testdox
  ```

  If the configured suite name differs, inspect `phpunit.xml.dist` and run the exact configured unit command; record the actual command and exit status.

- [x] **Step 3: Run syntax, diff and secret checks**

  Run:

  ```bash
  find public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests -name '*.php' -print0 | xargs -0 -n1 php -l
  git diff --check
  git status --short
  ```

  Review the diff for live IDs, credentials, tokens, generated artifacts and unrelated changes.

- [x] **Step 4: Read back execution state and update the checkpoint**

  Re-read `docs/architecture/V3_EXECUTION_STATE.md`, append a concise dated code-side checkpoint with the root cause, revision owner, tests actually run and the fact that no runtime deployment/live resume occurred. Do not claim staging/live repair.

- [x] **Step 5: Commit only after fresh verification**

  Stage only the implementation, regression test, and approved documentation/checkpoint files, then commit:

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Infrastructure/Graph/WpPostEndpointResolver.php public/wp-content/plugins/nhk-core/tests/Unit/RelationRevisionBindingTest.php docs/architecture/ARTICLE_INGEST_CONTRACT.md docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "fix(capture): hydrate article endpoint revision before governance"
  ```

  Do not deploy or resume Capture `01a0ae4c-0fe7-72b1-8222-ece526ce0faa` in this local checkpoint.
