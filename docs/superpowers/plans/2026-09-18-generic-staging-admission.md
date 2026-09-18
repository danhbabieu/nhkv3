# Generic Staging Admission Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make staging admission for canonical Capture-owned MediaUsage and related Capture workflows server-issued, exact, capability-bound, replay-safe, and independent of object-specific allowlists.

**Architecture:** The server constructs one canonical admission packet after resolving the exact Capture, Media, target, operation, selection fields, revisions, capability and payload fingerprints. A shared verifier signs and validates that packet; domain-specific admission providers validate only registered operation shape and live canonical ownership. Guards, Governance apply, retries and final read-back consume the same packet and fail closed on any mismatch.

**Tech Stack:** PHP 8.x, WordPress hooks, PHPUnit 11, NHK V3 domain/application services, WPDB repositories.

**Spec:** User request pasted in `/Users/imac24-2125d/.codex/attachments/b81f416d-98a1-4c50-a2cf-959521bd479f/pasted-text.txt`, governed by `AGENTS.md`, `docs/constitution/NHK_V3_CONSTITUTION.md`, `docs/architecture/04_MEDIA_MODEL.md`, and the Capture/MCP/Governance contracts.

## Global Constraints

- Never add the supplied case's Media, attachment, Classification, Capture or Post identifiers to production/runtime admission logic.
- Preserve fail-closed staging behavior, Governance, capability checks, revisions, idempotency and canonical read-back.
- `nhk.capture.ingest` remains the normal new-submission entry point; direct `nhk.media.bind` remains internal compatibility only.
- Do not mutate V2, staging or production data; do not use direct SQL or remote source edits.
- Preserve unrelated worktree changes in `RemoteMcpDocumentationVerifier.php` and its test.
- Update `docs/architecture/V3_EXECUTION_STATE.md` only after a verified checkpoint; read `docs/architecture/V2_V3_PARITY_MATRIX.md` before parity claims.

### Task 1: Establish failing generic-packet contract tests

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingStagingAdmissionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/MediaBindingServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceAutomationExpansionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/AuthorityStagingAdmissionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoW64StagingAdmissionTest.php`

**Interfaces:**
- Consumes the current verifier, admission, guard, Capture continuation and MediaBinding APIs.
- Produces executable tests for two unrelated exact fixture packets and tamper/replay behavior.

- [ ] **Step 1: Add RED tests for two distinct dynamic Media bindings.** Assert server issuance succeeds for unrelated UUIDs and stable keys, with no allowlist fields, and that the packet contains exact target stable key/revision, capability, request/plan/payload fingerprints, expiry and signature.
- [ ] **Step 2: Add RED tests for tampered Media, target UUID/stable key, operation, role, selection policy, payload, expired/bad-HMAC, missing capability and non-staging packets.** Assert rejection occurs before mutation.
- [ ] **Step 3: Add RED tests for idempotent retry, same-key changed-payload conflict, pinned representative protection, existing-Capture retry reuse, and no duplicate MediaUsage/Post/Capture.
- [ ] **Step 4: Add RED tests proving Authority and Video generic admission still pass and case-specific IDs are not required.
- [ ] **Step 5: Run the focused tests and record the expected failures.**

### Task 2: Implement canonical packet construction and shared exact verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScope.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingGuard.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/OperationScopedStagingGuard.php`

**Interfaces:**
- Consumes exact canonical packets produced by Capture/plan orchestration.
- Produces signed packets and verification methods that compare the complete canonical fingerprint, not isolated fields.

- [ ] **Step 1: Normalize and fingerprint the exact packet fields.** Include environment, authenticated capability, Capture identity/fingerprint, operation family, operation, canonical Media UUID, target type/UUID/stable key/revision, role, selection source/policy, request/plan/payload/dependency fingerprints, idempotency key, issued/expiry timestamps and replay binding.
- [ ] **Step 2: Require server-side exact resolution before issuance.** Reject missing canonical revisions/stable keys, unsupported registry operations, wildcard/fuzzy locators, stale dependencies and absent signing secret/capability.
- [ ] **Step 3: Make `verifyBindingRequest()` and `StagingAcceptanceScope::assertProposal()` validate the same canonical packet projection and payload fingerprint.
- [ ] **Step 4: Preserve direct Media compatibility as internal-only and make the guard use the shared verifier; reject modified packet fields, wrong Capture, stale revision, wrong capability or changed payload.
- [ ] **Step 5: Run Task 1 focused tests and keep the implementation minimal until green.

### Task 3: Replace case-specific admission providers with generic registered admission

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/MediaBindingStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/AuthorityStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoW64StagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`

**Interfaces:**
- Consumes server-constructed packets and live repository reads.
- Produces generic admission decisions for registered Media, Authority and Video operation families without object constants.

- [ ] **Step 1: Validate Media packet shape, exact live Media/Authority identity, active/readiness state, target type registry, target stable key/revision and representative eligibility.
- [ ] **Step 2: Generalize Video admission from exact plan/capture/target packet validation; remove object-specific constants and keep unsupported operations fail closed.
- [ ] **Step 3: Keep Authority generic validation and share packet shape/fingerprint helpers where the existing abstractions fit; do not weaken its plan/candidate closure checks.
- [ ] **Step 4: Register the generic providers once in the composition root and ensure runtime factory and Plugin boot use the same hook path.
- [ ] **Step 5: Run focused admission/verifier tests and the registry/catalog parity tests.

### Task 4: Verify Capture-owned binding, Governance and retry invariants

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/EditorialCaptureCoordinator.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Media/MediaBindingService.php`
- Modify: related tests from Task 1 only when assertions expose a real contract gap.

**Interfaces:**
- Consumes the shared packet and exact admission verifier from Tasks 2–3.
- Produces one-Capture/one-Post continuation, one active representative slot, contextual SEO in MediaUsage, and retry-safe receipts.

- [ ] **Step 1: Ensure normal `MEDIA_ENRICHMENT` uses `nhk.capture.ingest` and binds the server-issued packet before MediaBindingService mutation.
- [ ] **Step 2: Ensure retry with the original Capture/idempotency/fingerprint resumes existing phases without creating another Capture, Post, Media or MediaUsage.
- [ ] **Step 3: Ensure changed payload under the same idempotency key conflicts before mutation.
- [ ] **Step 4: Verify USER_EXPLICIT/PINNED cannot be replaced by SYSTEM_AUTO/AUTO and that Media, MediaAsset and MediaUsage boundaries remain distinct.
- [ ] **Step 5: Run the Capture, MediaBinding, Governance and retry-focused tests.

### Task 5: Remove object-specific runtime policy and document the generic boundary

**Files:**
- Modify: `AGENTS.md`
- Modify: `docs/architecture/04_MEDIA_MODEL.md`
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`
- Modify: `docs/mcp/MCP_V3_CONTENT_OPERATIONS.md`
- Modify: `docs/mcp/NHK_V3_CONTENT_OPERATIONS_CONTROL_PLANE.md`

**Interfaces:**
- Consumes verified implementation behavior and test evidence.
- Produces a policy/contract record that describes server-issued bounded admission without embedding acceptance-object IDs.

- [ ] **Step 1: Replace case-specific staging admission lists in project policy with the generic exact-packet contract and preserve explicit fail-closed/live-acceptance gates.
- [ ] **Step 2: Record the root cause, generic fix, rejected tamper cases, and verification checkpoint in execution state without claiming deployment or live mutation.
- [ ] **Step 3: Scan runtime source/policy for Atherton, W64, clock-tower, media 567/576, 400-day and other object-specific admission constants; allow fixtures/history only.
- [ ] **Step 4: Read `docs/architecture/V2_V3_PARITY_MATRIX.md` and update only evidence-backed parity notes.

### Task 6: Full verification and checkpoint

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md` only after all checks pass.

- [ ] **Step 1: Run focused staging/MediaBinding/Capture/Governance tests.
- [ ] **Step 2: Run broader Authority, Media, Capture, Governance and MCP suites.
- [ ] **Step 3: Run Composer lint, PHP lint for changed PHP files, `git diff --check`, secret review and hard-code scan.
- [ ] **Step 4: Inspect final `git diff` and `git status`; verify unrelated pre-existing changes remain intact.
- [ ] **Step 5: Commit only the requested implementation/docs/tests; do not deploy, push or mutate staging/production.

