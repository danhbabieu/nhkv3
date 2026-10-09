# Universal Semantic Recovery & Documentation Alignment Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Capture semantic recovery policy-version-aware, bounded and fail-closed, then prove the governed Knowledge read-back chain and align active documentation with runtime registries.

**Architecture:** Keep `CaptureCurrentOutcomeReducer` as the single lifecycle/retry/read authority. Add the server-owned semantic admission policy version to `CaptureDecisionDependencyFingerprint`, persist the current fingerprint for retryable outcomes, and explicitly preserve immutable Governance/authorization/identity/idempotency/CAS blocks. Verify the existing interpreter → guard → governed proposal → canonical read-back path across the registry rather than adding a new pipeline.

**Tech Stack:** PHP 8.x, PHPUnit 11, WordPress plugin application services, existing Capture/Governance/Knowledge repositories and runtime registries, Markdown contracts.

**Spec:** `docs/superpowers/specs/2026-10-09-universal-semantic-recovery-documentation-alignment-design.md`

## Global Constraints

- The NHK V3 Constitution is the only normative Constitution; conflicts remain `CONSTITUTION_CONFLICT` and are not weakened.
- WordPress native `wp_posts` remains editorial truth; Knowledge, Source/Evidence, Graph, Authority, Media and Video boundaries remain distinct.
- Semantic types, scopes, facets, predicates, dependencies, operations and public fields come only from executable registries/contracts.
- Preserve Capture UUID, request fingerprint, idempotency key, canonical UUID/stable key, optimistic revision, provenance, receipts and canonical read-back.
- No generic WordPress writer, direct SQL/database write, Governance bypass, migration, seed, import, backfill, hard delete, staging acceptance, production mutation, deployment or push.
- No transcript, OCR, generated prose, image hint or other derived material is promoted automatically to Knowledge or Evidence.
- Westminster remains a documentation/test example only; no shared runtime branch may mention its identity, route, slug or fixture data.
- A failing regression probe must be observed before changing production code; unchanged dependencies must not create an unbounded retry.
- Every checkpoint requires relevant tests, PHP lint, `git diff --check`, scoped secret review and an updated `docs/architecture/V3_EXECUTION_STATE.md`.

## Review Focus

- A legacy Capture evaluated before the semantic admission policy version exists must be stale once, not silently equivalent and not permanently replayable. Test in Task 1.
- `FAILED_RETRYABLE` must persist the decision needed to detect no progress, while `SYSTEM_BLOCKED` and terminal gates stay closed. Test in Tasks 1–3.
- `GOVERNANCE_APPROVAL_REQUIRED` is owner review, while Governance rejection/denial is terminal; policy changes must not collapse the distinction. Test in Task 3.
- A completed Knowledge owner with canonical read-back must be reused without a second apply, even when Capture is retried. Test in Task 4.
- Every registered Authority type must use its declared Knowledge scope and public eligibility must remain readiness-gated; no Article is created by a Knowledge delta. Test in Task 4 and document in Task 5.

---

### Task 1: Establish failing semantic-policy and bounded-retry probes

**Files:**
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticClaimCandidateGuardTest.php`
- Create: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureDecisionDependencyFingerprintTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: `SemanticClaimCandidateGuard::evaluate(array $candidate, array $interpretation): array`, `CaptureDecisionDependencyFingerprint::forState(...)`, `CaptureCurrentOutcomeReducer::currentDecision(...)` and existing `CaptureRecord` fixtures.
- Produces: named red tests that pin the policy-version payload, legacy Capture retry behavior, persisted `FAILED_RETRYABLE` fingerprint behavior and the existing explicit-user/derived admission boundary.

- [ ] **Step 1: Write the failing guard tests**

  Add tests for `RAW_EXPLICIT_USER_KNOWLEDGE` admission, dictionary-owner input remaining `REVIEW_REQUIRED`, and derived input remaining `REVIEW_REQUIRED`; use neutral synthetic text and UUIDs only.

- [ ] **Step 2: Write the failing fingerprint test**

  Build a deterministic Capture state and assert that the expected canonical fingerprint payload contains `SemanticClaimCandidateGuard::POLICY_VERSION` as a server-owned field, with `CaptureDecisionDependencyFingerprint::VERSION` set to the new format. The current implementation must fail because it has no semantic admission version in the payload.

- [ ] **Step 3: Write the failing reducer/state-machine tests**

  Add `test_legacy_semantic_policy_fingerprint_gets_one_bounded_reevaluation` and `test_retryable_failure_persists_current_fingerprint_for_no_progress_lock`. Assert same Capture UUID/request/idempotency identity, first stale retry eligible, and a second retry with the unchanged current fingerprint ineligible with `CAPTURE_RETRY_NOT_ALLOWED`.

- [ ] **Step 4: Run only the new probes and verify RED**

  Run:

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist \
    public/wp-content/plugins/nhk-core/tests/Unit/SemanticClaimCandidateGuardTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureDecisionDependencyFingerprintTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php \
    --filter 'semantic_policy|bounded_reevaluation|no_progress|raw_explicit|derived|dictionary'
  ```

  Expected: FAIL on the missing semantic policy fingerprint and missing retryable-state persistence; do not change runtime code if these probes do not fail.

- [ ] **Step 5: Commit the red regression tests**

  ```bash
  git add public/wp-content/plugins/nhk-core/tests/Unit/SemanticClaimCandidateGuardTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureDecisionDependencyFingerprintTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
  git commit -m "test: expose semantic recovery policy regression"
  ```

### Task 2: Version the admission policy and persist retryable fingerprints

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticClaimCandidateGuard.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureDecisionDependencyFingerprint.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/SemanticClaimCandidateGuardTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureDecisionDependencyFingerprintTest.php`
- Test: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`

**Interfaces:**
- Consumes: The red probes from Task 1 and existing `CaptureDecisionDependencyFingerprint::current(CaptureRecord $capture, array $input = []): string` behavior.
- Produces: `SemanticClaimCandidateGuard::POLICY_VERSION` as the source-controlled admission policy identity; a version-4 fingerprint payload with `semantic_admission_policy_version`; persisted current fingerprints for `REVIEW_REQUIRED` and `FAILED_RETRYABLE` only.

- [ ] **Step 1: Add the server-owned policy version**

  Define `public const POLICY_VERSION = 'semantic-admission-policy-1'` on `SemanticClaimCandidateGuard`. Do not read or override it from candidate, Capture, client or fixture input.

- [ ] **Step 2: Add the policy version to the fingerprint**

  Bump `CaptureDecisionDependencyFingerprint::VERSION` to `capture-decision-dependencies-4` and add the guard constant as a top-level canonical payload field named `semantic_admission_policy_version`; keep `POLICY_VERSION = 'video-intake-policy-2'` separate.

- [ ] **Step 3: Persist retryable-state fingerprints**

  In `EditorialCaptureCoordinator::save(...)`, compute the same current fingerprint for `REVIEW_REQUIRED` and `FAILED_RETRYABLE` after selecting `nextContext`, before receipt append/reconciliation. Do not persist it for completed, `SYSTEM_BLOCKED` or idempotency-conflict outcomes.

- [ ] **Step 4: Run the focused red tests and verify GREEN**

  Run the Task 1 command. Expected: all new policy/fingerprint/no-progress tests pass, and the existing Capture tests retain their prior behavior.

- [ ] **Step 5: Commit the bounded fingerprint change**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Semantic/SemanticClaimCandidateGuard.php \
    public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureDecisionDependencyFingerprint.php \
    public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php \
    public/wp-content/plugins/nhk-core/tests/Unit/SemanticClaimCandidateGuardTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureDecisionDependencyFingerprintTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php
  git commit -m "fix: version semantic admission recovery dependencies"
  ```

### Task 3: Make immutable retry gates explicit

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`

**Interfaces:**
- Consumes: `CaptureCurrentOutcomeReducer::currentDecision(...)`, the versioned fingerprint from Task 2, and existing `classifiedFailure(...)` status/blocker mapping.
- Produces: one explicit immutable retry-gate classifier used before retryable-status reopening; Governance approval remains reviewable while Governance denial/rejection is `SYSTEM_BLOCKED`.

- [ ] **Step 1: Add failing immutable-gate tests**

  Add reducer cases where a changed semantic policy fingerprint cannot reopen `SYSTEM_BLOCKED`, `GOVERNANCE_REJECTED`, `GOVERNANCE_DENIED`, authorization/capability denial, identity conflict, idempotency conflict or CAS/stale-binding failures. Add the positive control that `GOVERNANCE_APPROVAL_REQUIRED` remains reviewable.

- [ ] **Step 2: Add failing classification tests**

  Extend `GovernedCaptureContinuationServiceTest` so rejection/denial codes classify as `SYSTEM_BLOCKED`, while `GOVERNANCE_APPROVAL_REQUIRED` remains `REVIEW_REQUIRED` with the existing blocker.

- [ ] **Step 3: Implement the reducer gate**

  Add a private helper in `CaptureCurrentOutcomeReducer` that derives immutable retry blocking from current failure classification and explicit registered failure-code families, including `SYSTEM_BLOCKED`, `GOVERNANCE_REJECTED`, `GOVERNANCE_DENIED`, `AUTHORIZATION_*`, `CAPABILITY_*`, `IDEMPOTENCY_*`, `*_CAS_*`, `CAS_*`, `*_BINDING_CONFLICT`, identity/subject conflict and invariant/contract/schema/not-found failures. Evaluate it before the `FAILED_RETRYABLE` and stale-review branches; preserve the existing hard-block and article-overlap behavior, and do not classify `GOVERNANCE_APPROVAL_REQUIRED` as immutable.

- [ ] **Step 4: Implement governed failure classification**

  Extend only the existing `classifiedFailure(...)` mapping in `GovernedCaptureContinuationService`; do not add a new public status or bypass Governance. Keep unknown/invariant/contract/binding/idempotency/CAS/identity failures fail-closed.

- [ ] **Step 5: Run the focused reducer/Governance tests**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php
  ```

  Expected: PASS, with immutable gates closed across policy changes and approval-required review preserved.

- [ ] **Step 6: Commit the gate classification**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Capture/CaptureCurrentOutcomeReducer.php \
    public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php \
    public/wp-content/plugins/nhk-core/tests/Unit/CaptureCurrentOutcomeReducerTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php
  git commit -m "fix: keep semantic recovery gates fail closed"
  ```

### Task 4: Prove governed Knowledge read-back and registry-wide behavior

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/PublicKnowledgeEligibilityTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfilePublicDossierTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php`

**Interfaces:**
- Consumes: `TextInputInterpreter`, `SemanticClaimCandidateGuard`, `GovernedCaptureContinuationService`, existing in-memory Knowledge/Source/Evidence repositories, `CanonicalEntityTypeCatalog`, `EntityTypeRegistry` and public dossier eligibility services.
- Produces: end-to-end tests proving proposal → Governance → canonical Knowledge read-back, no replay of applied owners, declared scope for all nine Authority types, explicit dependency/readiness states and no Article side effect for a Knowledge delta.

- [ ] **Step 1: Add the real interpreter-to-read-back regression**

  Extend the existing real interpreter test with a governed lifecycle/repository double that records proposal, approval/apply and canonical read-back. Assert the candidate is explicit user Knowledge, the proposal carries the original Capture/idempotency identity, the canonical owner is read back by stable identity/revision, and a retry reuses the read-back without a second apply.

- [ ] **Step 2: Add registry-wide scope assertions**

  Drive the existing `CanonicalEntityTypeCatalog` enumeration used by the runtime registry rather than a Westminster fixture and assert each of `brand`, `model`, `variant`, `movement`, `music`, `component`, `classification`, `specimen` and `product` uses its declared Knowledge scope. Keep duplicate-candidate, distinct-provenance and missing/pending Source/Evidence assertions.

- [ ] **Step 3: Add public and editorial boundary assertions**

  Assert a public dossier is eligible only after canonical readiness/read-back, unknown or unavailable Knowledge remains fail-closed, and a `KNOWLEDGE_DELTA` continuation creates no Article/Post. Use synthetic UUIDs and neutral names.

- [ ] **Step 4: Run the governed/e2e/dossier slice**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist \
    public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/PublicKnowledgeEligibilityTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EntityProfilePublicDossierTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php
  ```

  Expected: PASS; no runtime or database acceptance is attempted.

- [ ] **Step 5: Commit the governed-chain coverage**

  ```bash
  git add public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EditorialCaptureConvergenceE2ETest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/PublicKnowledgeEligibilityTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EntityProfilePublicDossierTest.php \
    public/wp-content/plugins/nhk-core/tests/Unit/EntityProfileRegistryTest.php
  git commit -m "test: verify governed knowledge readback parity"
  ```

### Task 5: Align active documentation and close verification

**Files:**
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`
- Modify: `docs/architecture/UNIVERSAL_STRUCTURED_SEMANTIC_INTAKE_CONTRACT.md`
- Modify: `docs/architecture/06_KNOWLEDGE_SOURCE_MODEL.md`
- Modify: `docs/architecture/GOVERNED_LIVING_KNOWLEDGE_DESIGN.md`
- Modify: `docs/architecture/ARTICLE_INGEST_CONTRACT.md`
- Modify: `docs/architecture/MUSIC_DATA_COLLECTION_STANDARD.md`
- Modify: `docs/architecture/PUBLIC_ENTITY_DOSSIER_PROJECTION_CONTRACT.md`
- Modify: `docs/architecture/CURRENT_DOCUMENTATION_STATUS_INDEX.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php` (only if the changed active documents require an allowlist/snapshot assertion)

**Interfaces:**
- Consumes: the executable policy/version names and test evidence from Tasks 2–4, existing documentation registry keys and the current Constitution/contract terminology.
- Produces: one aligned contract chain `Document Contract → Runtime Registry → Input Validation → Execution → Persistence → Public Projection`, with status/index links and no duplicated conflicting rule.

- [ ] **Step 1: Update Capture/MCP contract language**

  Document the sole Capture entry, same-idempotency retry, policy-versioned dependency fingerprint, one bounded re-evaluation, no-progress lock, append-only receipts and fail-closed immutable gates in the two MCP operation documents.

- [ ] **Step 2: Update semantic/Knowledge ownership language**

  Document explicit-user versus derived admission, the guard policy version as server-owned, Knowledge scope/provenance, Source/Evidence pending/rejected dependency states, Governance proposal/read-back and the no-Article boundary in the Universal Intake, Knowledge Source Model, Governed Living Knowledge and Article Ingest contracts.

- [ ] **Step 3: Update Music and public dossier boundaries**

  Keep Music collection read-only and rights/notation/audio distinctions explicit; keep Westminster noncanonical. State that public dossier output requires direct canonical Knowledge identity, readiness and public eligibility.

- [ ] **Step 4: Add the registry parity table and update the index**

  Add the nine-type matrix to the Universal Structured Semantic Intake contract (or the existing active matrix location if the repository already has one), mapping each type through document, registry, validation, execution, persistence and projection. Update only status/link metadata in `CURRENT_DOCUMENTATION_STATUS_INDEX.md`.

- [ ] **Step 5: Record the implementation checkpoint**

  Append evidence, focused test counts, full-suite baseline, lint/diff/secret results and the explicit no-mutation/no-deployment boundary to `V3_EXECUTION_STATE.md`. Do not claim external publish or authorized TEST runtime acceptance.

- [ ] **Step 6: Run final verification before completion claims**

  Run the focused Capture/Governance/e2e/doc slice, regenerate the documentation snapshot with `composer generate:mcp-docs` when the active allowlist snapshot changes, then run `php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit.xml.dist`, changed-file PHP lint, JavaScript syntax checks where applicable, `git diff --check`, and scoped secret review. Compare failures to the pre-change baseline and report only new failures as regressions.

- [ ] **Step 7: Commit the documentation and verification checkpoint**

  ```bash
  git add docs/mcp docs/architecture public/wp-content/plugins/nhk-core/tests/Unit/McpDocumentationRegistryTest.php
  git diff --cached --check
  git commit -m "docs: align semantic recovery contracts and parity"
  ```
