# Dictionary Enrichment Audit and Safe Planning Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a generic bounded Dictionary enrichment audit, deterministic plan and guarded lexical apply path for every public Entry/Sense without inventing semantic truth.

**Architecture:** Build a pure application audit over existing Entry/Sense repositories and bounded owner/projection callbacks. Resolve owners only from strong evidence, serialize explicit coverage/status packets, and produce a canonical fingerprinted plan. Route optional apply through the existing `DictionaryMutationService`; never write Knowledge, Graph, Media, Video or Article data from Dictionary.

**Tech Stack:** PHP 8.x, existing NHK Core Dictionary application/repository boundaries, PHPUnit, WordPress/MCP transport registration.

**Spec:** `docs/superpowers/specs/2026-10-03-dictionary-enrichment-audit-design.md`

## Global Constraints

- “More content” is not a reason to invent a semantic relation.
- No Dictionary Graph endpoint, new semantic type/predicate, raw SQL mutation, legacy article-body migration, staging/production mutation or bulk best-guess mapping.
- Only `EXACT_UNIQUE` owner matches may produce a ready semantic-reference action.
- Public requests never execute enrichment audit; audit and plan are bounded internal/admin diagnostics.
- Apply requires exact fingerprint, CAS, idempotency, audit and canonical read-back.
- Do not modify `style.css` 1.3.5 or the existing expectation 1.3.3.
- Preserve and classify pre-existing baseline failures; do not weaken tests.

## Review Focus

- Unavailable owner repositories must remain `UNAVAILABLE`, not become zero coverage — covered by audit coverage-status tests.
- A label-only or keyword-similar owner must remain non-actionable — covered by resolver evidence tests.
- Replayed or stale plans must not duplicate Forms or bypass CAS — covered by apply idempotency/CAS tests.
- Mention observations must not become semantic relations — covered by owner-candidate tests.
- Public detail/search must remain bounded and render standalone lexical Entries — covered by public wiring tests.

---

### Task 1: Define audit and plan value contracts

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEnrichmentAudit.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEnrichmentPlan.php`
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEnrichmentOwnerResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEnrichmentAuditTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEnrichmentPlanTest.php`

**Interfaces:**
- `DictionaryEnrichmentAudit::__construct(object $entries, object $concepts, callable $coverage, callable $ownerResolver, callable $relatedProjection = null)`.
- `audit(int $limit, ?string $cursor = null, ?string $entryId = null, ?string $senseId = null, bool $publicOnly = true): array` returns `status`, `items`, `next_cursor`, `has_more`, `read_only`, `mutated`.
- `DictionaryEnrichmentOwnerResolver::resolve(DictionaryConcept $sense, array $context = []): array` returns `classification`, `target`, `evidence`, `reason`.
- `DictionaryEnrichmentPlan::build(array $audit, array $options = []): array` returns `status`, ordered `actions`, `owner_candidates`, `fingerprint`.

- [ ] **Step 1: Write failing audit tests** for public bounded Entries, cursor continuation, all semantic-reference states, explicit coverage statuses, required counts/classifications and no mutation.
- [ ] **Step 2: Run the focused audit test** and verify it fails for missing classes.
- [ ] **Step 3: Implement the audit DTO/serializer and cursor-bounded iteration**, reusing `DictionaryDetailQuery`-compatible repository methods where available and never falling back to an unbounded public scan.
- [ ] **Step 4: Write failing resolver/plan tests** for `EXACT_UNIQUE`, `AMBIGUOUS`, `NO_OWNER`, `OWNER_MISSING`, `CONFLICT`, approved-label Form readiness, duplicate `NOOP`, unsupported hidden Form blocking and rejected candidate/private sources.
- [ ] **Step 5: Implement evidence ordering and deterministic plan canonicalization/fingerprinting**; keep owner-pipeline candidates separate from executable actions.
- [ ] **Step 6: Run both focused tests** and verify PASS with no existing tests modified.
- [ ] **Step 7: Commit** `feat(dictionary): add enrichment audit and planning contracts`.

### Task 2: Add owner-backed coverage adapters

**Files:**
- Create: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEnrichmentCoverage.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryRuntime.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Dictionary/DictionaryBootstrap.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEnrichmentCoverageTest.php`

**Interfaces:**
- `DictionaryEnrichmentCoverage::forReference(string $type, string $id, array $context = []): array` returns independent packets for `knowledge`, `media`, `video`, `articles`, `brands`, `models`, `specimens`, `mentions` and `related_terms`.
- Each packet contains `status`, `count` and bounded `items`/`representative` fields; unavailable infrastructure is never represented as empty.

- [ ] **Step 1: Write failing coverage tests** for available, empty, unavailable, blocked and ambiguous owner states, including Mention counts by source kind.
- [ ] **Step 2: Run the test** and verify failure.
- [ ] **Step 3: Implement the adapter** by composing existing dossier, semantic, Mention and RelatedTerm projections with bounded limits; do not add a Dictionary-owned relation or perform N×N traversal.
- [ ] **Step 4: Wire the adapter into `DictionaryRuntime`** without changing public hub/detail call paths.
- [ ] **Step 5: Run focused coverage/detail tests** and verify standalone lexical entries still work when the adapter is unavailable.
- [ ] **Step 6: Commit** `feat(dictionary): add bounded owner coverage audit adapters`.

### Task 3: Implement deterministic Form and semantic-reference planning

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEnrichmentPlan.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryMutationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryEntrySenseResolver.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEnrichmentPlanTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryMutationContractTest.php`

**Interfaces:**
- Plan actions use only `ADD_ENTRY_FORM`, `SET_SEMANTIC_REFERENCE`, `NOOP`, `REVIEW_REQUIRED`.
- Form candidates carry `entry_id`, `sense_id`, `current_revision`, `form`, `kind`, `locale`, `evidence`, `risk` and `status`.
- Semantic-reference actions carry exact Entry/Sense IDs, current Entry revision, typed target and target revision when available.

- [ ] **Step 1: Add failing tests** for approved legacy label → Form, duplicate Form → `NOOP`, hidden unsupported → `BLOCKED`, stale target → `BLOCKED`, and the generic 400-day evidence packet.
- [ ] **Step 2: Run the tests** and verify failure.
- [ ] **Step 3: Implement plan construction** from durable approved labels and explicit destinations only; use existing resolver/mapping state and reject candidate/private/article-text-derived labels.
- [ ] **Step 4: Extend mutation read-back validation** only where necessary to accept the plan’s exact Form kind/locale and existing semantic-reference method; retain CAS/idempotency behavior.
- [ ] **Step 5: Run Dictionary mutation and plan suites** and verify no Graph/Knowledge/Media/Video writes are reachable.
- [ ] **Step 6: Commit** `feat(dictionary): plan durable lexical enrichment safely`.

### Task 4: Add guarded audit/plan/apply MCP/admin operations

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpDictionaryHandler.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Mcp/McpTransport.php`
- Modify: existing Dictionary MCP registry/bootstrap file identified by the current registration tests
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/McpDictionaryToolsContractTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryEnrichmentMcpTest.php`

**Interfaces:**
- `nhk.dictionary.enrichment.audit` accepts bounded `limit`, `cursor`, optional exact scope and returns read-only audit output.
- `nhk.dictionary.enrichment.plan` accepts the same bounded scope plus audit snapshot/options and returns a deterministic plan/fingerprint.
- `nhk.dictionary.enrichment.apply` is internal/admin-only, accepts exact plan/fingerprint/idempotency key and returns explicit apply/read-back diagnostics; it is absent or rejected when guarded capability is unavailable.

- [ ] **Step 1: Write failing transport/handler tests** for registration, bounded input validation, read-only flags, public rejection and unavailable-runtime behavior.
- [ ] **Step 2: Run the tests** and verify failure.
- [ ] **Step 3: Implement handler methods and transport cases** using the runtime services; preserve existing MCP error vocabulary and capability checks.
- [ ] **Step 4: Add apply tests** for exact fingerprint, CAS conflict, idempotent replay, audit receipt and canonical read-back.
- [ ] **Step 5: Run MCP Dictionary focused suites** and verify no public mutation route is registered.
- [ ] **Step 6: Commit** `feat(dictionary): expose guarded enrichment diagnostics`.

### Task 5: Verify public Form/search/detail boundaries

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryPublicQuery.php` only if durable Form ranking is not already wired
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Dictionary/DictionaryDetailQuery.php` only if Form/semantic-reference read-back gaps remain
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Http/PublicDictionaryRoutes.php` only if indexability wiring is incomplete
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryPublicQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/DictionaryDetailQueryTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/PublicDictionaryRoutesTest.php`

- [ ] **Step 1: Add failing regression tests** for `400`, `400 ngày`, `400-Day Clock`, `Anniversary clock`, `Jahresuhr/400`, durable Form ranking, absent enrichment and no audit call during hub/detail requests.
- [ ] **Step 2: Run focused public tests** and verify any gap is reproduced.
- [ ] **Step 3: Implement only the smallest missing read/projection wiring**; keep compatibility labels fallback-only and omit empty sections.
- [ ] **Step 4: Run public Dictionary/search/SEO suites** and verify indexability/JSON-LD/sitemap behavior remains truthful.
- [ ] **Step 5: Commit** `fix(dictionary): consume durable forms in public discovery` if code changes are required; otherwise record verification without a no-op commit.

### Task 6: Add canonical operations documentation and checkpoint evidence

**Files:**
- Create: `docs/architecture/DICTIONARY_ENRICHMENT_AUDIT_OPERATIONS.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Test/verification: relevant focused suite outputs and changed-scope secret review

- [ ] **Step 1: Document lexical enrichment versus canonical-owner enrichment**, evidence order, statuses, bounded inputs, plan fingerprint, apply safeguards and owner-pipeline handoff.
- [ ] **Step 2: Update documentation status index** only if the new operations document is an active runtime contract/reference.
- [ ] **Step 3: Run PHP lint for changed PHP files, focused PHPUnit suites, `git diff --check` and secret review.** Record exact verified counts and pre-existing failures only.
- [ ] **Step 4: Update `V3_EXECUTION_STATE.md`** with implementation status, data mutation status, test evidence and remaining gaps; do not claim live enrichment if runtime is unavailable.
- [ ] **Step 5: Commit** `docs(dictionary): document enrichment audit operations`.

### Task 7: Final verification and integration review

**Files:**
- Review all files changed by Tasks 1–6
- Test: focused Dictionary/MCP/frontend suites plus changed-file lint

- [ ] **Step 1: Run the complete relevant focused suite** and capture failures by pre-existing/new classification.
- [ ] **Step 2: Run `git diff --check` and changed-scope secret review.**
- [ ] **Step 3: Inspect the final diff for Constitution conflicts, public-route regressions, unbounded scans and unauthorized mutation paths.**
- [ ] **Step 4: Produce the final report** with `SYSTEM_GAPS_FIXED`, enrichment audit counts, applied/no-op/review/blocked counts, owner coverage, Mention/RelatedTerm coverage, 400-day status, tests, commits, remaining gaps and verdict `READY_FOR_DEPLOY`, `DATA_PLAN_READY` or exact blocked reason.
