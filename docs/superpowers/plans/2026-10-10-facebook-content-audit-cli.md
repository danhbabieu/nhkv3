# Facebook Content Audit CLI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a fixture-first, strictly read-only Facebook Page/group-post audit CLI for the single approved NHK pilot Page.

**Architecture:** A small application boundary owns scope verification, access results, normalization, bounded collection, classification and workbook projection. Fixture and Meta adapters share a read-only interface; no database, semantic owner, Governance path, Admin UI or MCP endpoint is introduced.

**Tech Stack:** PHP 8.2+, existing NHK PSR-4 autoload, PHPUnit 11, native `ZipArchive`, OOXML workbook parts, JSON fixtures/checkpoints.

**Spec:** `docs/superpowers/specs/2026-10-10-facebook-content-audit-design.md`

## Global Constraints

- `READ_ONLY` tuyệt đối.
- `SINGLE_PAGE_SCOPE` is exactly `https://www.facebook.com/donghonhakho.vn`.
- No semantic owner, schema, table, migration, mutation path, Admin UI or MCP endpoint.
- No credentials in fixtures, checkpoints, logs or workbook output.
- No live collection before verified Page ID and access.
- `DELETE_CANDIDATE` is report-only.
- Missing values remain distinct as `NULL`, `UNAVAILABLE`, `INACCESSIBLE`, or `0`.

## Review Focus

- A different URL or unverified Page ID must stop before collection — scope and identity tests.
- A repeated cursor or transient page failure must not silently drop rows — pagination/retry tests.
- A private/unsupported group surface must not become “no posts” — access/group tests.
- User-controlled post text must not become an Excel formula — workbook injection test.
- A zero metric must not become a missing metric — normalization and reload test.

---

### Task 1: Read-only scope, value states, access and adapter contracts

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Domain/FacebookAudit/FacebookAuditScope.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/FacebookAudit/FacebookValue.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/FacebookAudit/IdentityVerification.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/FacebookAudit/AccessReport.php`
- Create: `public/wp-content/plugins/nhk-core/src/Domain/FacebookAudit/ReadPage.php`
- Create: `public/wp-content/plugins/nhk-core/src/Contracts/FacebookAudit/FacebookAuditReadAdapter.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditScopeTest.php`

**Interfaces:**
- Produces `FacebookAuditScope::forTarget(string $url, ?string $verifiedPageId): self`, immutable scope validation, and typed identity/access/page result objects.
- Produces adapter methods `verifyIdentity`, `inspectAccess`, `pagePosts`, and `groupPosts`; no write method is permitted.

- [ ] **Step 1: Write failing scope tests** for canonical URL acceptance, URL mismatch rejection, missing Page ID rejection, and unverified identity state.
- [ ] **Step 2: Run `vendor/bin/phpunit public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditScopeTest.php` and confirm the expected missing-class failures.**
- [ ] **Step 3: Implement the immutable scope/value/result classes and read-only interface.** Preserve explicit zero and non-zero value states.
- [ ] **Step 4: Re-run the focused test and confirm it passes.**

### Task 2: Fixture adapter, normalization, pagination, checkpoint and retry

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookAuditNormalizer.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookAuditCheckpoint.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookAuditCollector.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/FacebookAudit/FixtureFacebookAuditReadAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/FacebookAudit/JsonFacebookAuditCheckpointStore.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Fixtures/facebook-audit-pilot.json`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditCollectionTest.php`

**Interfaces:**
- Consumes Task 1 scope and adapter contracts.
- Produces normalized page/group rows, checkpoint JSON with cursor and row keys, and a collector result that distinguishes retryable, unavailable, and inaccessible states.

- [ ] **Step 1: Write failing fixture tests** for multi-page collection, resume without duplicate rows, retry then success, repeated cursor rejection, and all four missing-value states.
- [ ] **Step 2: Run the focused collection test and verify it fails for the absent fixture adapter/collector.**
- [ ] **Step 3: Implement fixture JSON loading, normalization, monotonic cursor checks, bounded retry, checkpoint persistence, and fixture group-source labels.**
- [ ] **Step 4: Re-run the focused collection tests and confirm they pass.**

### Task 3: Classification and duplicate detection

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookAuditClassifier.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookDuplicateDetector.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditClassificationTest.php`

**Interfaces:**
- Consumes normalized post/group rows from Task 2 and an explicit review lexicon.
- Produces one primary classification, reason codes, duplicate groups and counts for the workbook overview.

- [ ] **Step 1: Write failing tests** for content-type/age-bucket low engagement, exact normalized duplicate matching, review-only trademark flagging, delete-candidate guardrails, and insufficient data.
- [ ] **Step 2: Run the focused classification test and confirm the expected failures.**
- [ ] **Step 3: Implement deterministic priority, quartile calculation, fingerprinting and report-only delete-candidate logic.**
- [ ] **Step 4: Re-run the focused classification test and confirm it passes.**

### Task 4: Secure OOXML workbook writer

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/FacebookAudit/FacebookAuditWorkbook.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/FacebookAudit/NativeXlsxWriter.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditWorkbookTest.php`

**Interfaces:**
- Consumes the aggregate audit result from Tasks 2–3.
- Produces a `.xlsx` with the nine required sheets, hyperlinks, Unicode text, explicit state values and no formulas from user content.

- [ ] **Step 1: Write failing workbook tests** for required sheet names, Vietnamese text, direct hyperlink relationships, formula-injection escaping, null/zero distinction, and secret absence.
- [ ] **Step 2: Run the focused workbook test and confirm the writer is absent.**
- [ ] **Step 3: Implement fixed-path OOXML generation with `ZipArchive`, relationship-backed hyperlinks, XML escaping and dangerous-prefix neutralization.**
- [ ] **Step 4: Re-open the generated ZIP/XML in the test and confirm all workbook assertions pass.**

### Task 5: Read-only Meta adapter and CLI boundary

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/FacebookAudit/MetaFacebookAuditReadAdapter.php`
- Create: `public/wp-content/plugins/nhk-core/src/Infrastructure/FacebookAudit/MetaGraphHttpClient.php`
- Create: `tools/facebook-content-audit.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/MetaFacebookAuditReadAdapterTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditCliContractTest.php`

**Interfaces:**
- Consumes the Task 1 adapter boundary and injected HTTP client; Graph host is allowlisted and only GET requests are possible.
- Produces identity/access/read results and fail-closed CLI exit diagnostics; it never performs live collection in the test suite.

- [ ] **Step 1: Write failing tests** for GET-only behavior, exact host/path scope, missing credential refusal, unverified identity refusal, and secret redaction.
- [ ] **Step 2: Run the focused adapter/CLI tests and confirm the expected failures.**
- [ ] **Step 3: Implement the injected Graph client, read-only Meta adapter, fixture/Meta mode selection, and CLI argument validation.**
- [ ] **Step 4: Re-run focused tests and verify no real network call is made.**

### Task 6: Fixture end-to-end report and verification evidence

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/FacebookAuditCliContractTest.php`
- Modify: `docs/superpowers/specs/2026-10-10-facebook-content-audit-acceptance.md`
- Create: `docs/superpowers/reports/2026-10-10-facebook-content-audit-implementation.md`

**Interfaces:**
- Consumes the complete runner, classifier and workbook writer.
- Produces a reproducible fixture report and evidence-backed implementation report; no live Meta result is claimed.

- [ ] **Step 1: Write the failing end-to-end assertion** for the required overview keys and workbook location.
- [ ] **Step 2: Run the focused end-to-end test and confirm it fails before wiring the CLI.**
- [ ] **Step 3: Wire the fixture CLI path and generate a temporary workbook/checkpoint during the test.**
- [ ] **Step 4: Run focused PHPUnit, then the full PHPUnit suite, PHP lint and `git diff --check`; record exact results in the report.**
- [ ] **Step 5: Mark only verified acceptance criteria complete and leave live Meta status as blocked/unverified.**

## Plan self-review

- Scope lock, identity, access, pagination, checkpoint, retry, group provenance,
  normalization, classification, duplicate detection, workbook safety, Meta
  fail-closed behavior and required verification each have an owning task.
- No task introduces persistence, a semantic owner, a Governance call, a write
  adapter, a new endpoint or a production operation.
- All later interfaces consume only earlier task outputs; workbook projection
  remains downstream of the read-only audit aggregate.

