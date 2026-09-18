# Generic Dynamic Video Staging Acceptance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace case-specific Video staging admission with one server-issued, exact, signed Capture-owned scope that supports new Video ingest and existing Video update/correction.

**Architecture:** Extend the existing `StagingAcceptanceScopeVerifier` and `VideoStagingAdmission` boundary. The verifier derives a canonical Video binding from the persisted Capture, the server-owned plan, and the read-only Video repository; the admission provider validates that exact binding before signing. `GovernedCaptureContinuationService`, `OperationScopedStagingGuard`, Proposal eligibility, and `ControlledApplyService` remain the existing lifecycle owners.

**Tech Stack:** PHP 8.x, WordPress plugin runtime, PHPUnit 11.5, Composer scripts, existing `CommandCanonicalizer`, `VideoRepository`, HMAC scope signing, and CAS-based Governance apply.

**Spec:** `docs/superpowers/specs/2026-09-18-generic-dynamic-video-staging-acceptance-design.md`

## Global Constraints

- Modify only the local worktree; no SSH, server edit, push, pull, rsync, SCP, or deployment.
- Preserve unrelated working-tree changes; do not use destructive Git operations.
- No direct SQL, generic WordPress writer, Governance bypass, CAS bypass, or client-supplied authorization packet.
- Production remains fail-closed; only `nhk.capture.ingest` may issue Capture-owned Video scopes.
- No Capture/Video/subject IDs, YouTube IDs, W64 exceptions, or growing allowlists in runtime policy.
- No database migration, Proposal retrofit, semantic backfill, or staging/production data mutation.
- Knowledge, Source/Evidence, Graph, Authority, Article, and unrelated Media writes remain separately governed.
- Tests are written RED before implementation, and every task ends with its focused verification.

## File map

- Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php`: derive, sign, and verify the complete Video binding; use the existing Video repository for ingest duplicate audit.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoStagingAdmission.php`: validate generic exact Video scopes for `ingest` and `update`, including Capture intent, policy, source identity, subject packet, fingerprints, and duplicate state.
- Modify `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`: attach a server-issued scope for `ingest` and `update`; never preserve connector-provided scope data.
- Modify `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`: compose the verifier with the canonical `VideoRepository` while preserving existing constructor compatibility.
- Modify `public/wp-content/plugins/nhk-core/src/Plugin.php`: register the generic admission provider with the canonical `WpdbVideoRepository`; remove reliance on `VideoW64StagingAdmission`.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php`: RED and regression coverage for complete signed Video packets and Proposal verification.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php`: RED and regression coverage for generic policy decisions.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`: scope attachment and client-packet rejection coverage.
- Modify `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceAutomationExpansionTest.php` and `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`: prove staging eligibility and apply remain bounded by the exact scope.
- Remove `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoW64StagingAdmission.php` and its test only after generic W64 parity is green and no runtime registration remains.
- Modify `docs/architecture/V3_EXECUTION_STATE.md`: record the local generic Video staging checkpoint and verification evidence.

### Task 1: Add RED tests for exact new-ingest and update bindings

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php`

**Interfaces:**
- Consumes: current `StagingAcceptanceScopeVerifier::issueForVideoPlan(CaptureRecord, array): array`, `VideoStagingAdmission::__invoke(bool, array, CaptureRecord, array, array): bool`, `Proposal`, and `VideoRepository` shapes.
- Produces: failing executable examples defining the packet fields and rejection codes for later implementation.

- [ ] **Step 1: Create fixtures for a VIDEO Capture with a new YouTube plan.**

  Build a `CaptureRecord` whose context contains `purpose => VIDEO`, whose single video asset contains a server-owned proposal with `operation => ingest`, proposed canonical UUID, and metadata source `{platform, external_video_id, canonical_source_url}` plus an exact `subject_resolution_packet` of type `classification`. Add a repository double implementing `VideoRepository::findByExternalReference()` and returning `null`.

- [ ] **Step 2: Add the valid-ingest RED test.**

  Call `issueForVideoPlan()` with the ingest plan and assert the packet contains `environment`, `canonical_entrypoint`, `operation_family`, Capture ID/fingerprint, `plan_fingerprint`, `proposal_command_fingerprint`, source identity, proposed UUID, subject packet, expiry, fingerprint, and signature. Build a Video Proposal from that packet and assert `verifyProposal()` is true.

- [ ] **Step 3: Add the valid-update RED test.**

  Build an existing `Video` at revision 5, a Capture-owned update plan with target UUID and `expected_revision => 5`, and assert the issued packet verifies against a Proposal with matching target, operation, subject, plan, and revision.

- [ ] **Step 4: Add tamper and duplicate RED tests.**

  Mutate one binding field at a time—external ID, source URL, Capture fingerprint, subject UUID/type/revision, target UUID, operation, plan fingerprint, Proposal command fingerprint, and signature—and assert verification/admission is false. Make the Video repository return an existing Video for the ingest external identity and assert issuance throws `STAGING_VIDEO_DUPLICATE`.

- [ ] **Step 5: Add direct-entrypoint, client-scope, expiry, production, and revision RED tests.**

  Assert a payload-provided `staging_acceptance` cannot be reused, `nhk.video.ingest` is rejected, expired/tampered packets fail, production issuance throws `STAGING_PRODUCTION_FORBIDDEN`, and a Proposal whose canonical revision has advanced from 5 to 6 fails closed.

- [ ] **Step 6: Run the focused tests and record the expected failures.**

  Run:

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'StagingAcceptanceScopeVerifierTest|VideoStagingAdmissionTest|GovernedCaptureContinuationServiceTest'
  ```

  Expected: the new ingest/binding assertions fail because the current verifier only supports update-like operations and does not include the complete binding.

- [ ] **Step 7: Commit the RED tests.**

  ```bash
  git add public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php
  git commit -m "test(video): define dynamic staging scope contract"
  ```

### Task 2: Implement canonical Video packet issuance and verification

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php`

**Interfaces:**
- Consumes: Task 1 packet fixtures, `VideoRepository`, `CommandCanonicalizer`, and existing HMAC/expiry helpers.
- Produces: `issueForVideoPlan()` packets with exact source/subject/target/plan/Proposal bindings; `verifyProposal()` rejects any mismatch.

- [ ] **Step 1: Add an optional canonical Video repository dependency without breaking existing tests.**

  Extend the verifier constructor with a nullable `VideoRepository` argument after the existing optional capability callback, or use a named argument in the composition root. Keep current Media and Authority call sites valid. Pass `$videos` from `GovernanceRuntimeFactory` using a named argument.

- [ ] **Step 2: Normalize the plan into one unsigned Video binding.**

  Add a private helper that extracts the Video payload from the Capture's single `kind => video` asset, resolves source data from `metadata.source` or `metadata.source_snapshot`, and requires valid platform, external ID, canonical URL, Video UUID, operation, subject packet, plan fingerprint, and Proposal command fingerprint. For YouTube, construct `YouTubeSourceSnapshot::fromArray()` so platform, 11-character external ID, and canonical URL are validated by the domain contract; do not accept a connector scope.

- [ ] **Step 3: Implement ingest semantics.**

  Accept `operation => ingest`, require a valid proposed UUID and no target revision, call `VideoRepository::findByExternalReference(platform, external_video_id)`, and refuse issuance if a canonical duplicate exists. Encode explicit `create_semantics => ingest` and the exact proposed UUID in the packet.

- [ ] **Step 4: Implement update semantics.**

  Accept `operation => update`, require target UUID and positive expected revision, read the canonical target by UUID, and require its current revision to equal the plan revision. Encode target UUID and expected revision; do not refresh the revision after packet creation.

- [ ] **Step 5: Add policy and fingerprints to the packet.**

  Include `semantic_write_policy => PROJECT_BUILD`, `canonical_entrypoint => nhk.capture.ingest`, request fingerprint, exact plan fingerprint, canonical Video identity, subject packet, and a fingerprint of the Proposal command with authorization fields removed. Pass the complete unsigned packet to the existing admission hook before calculating packet fingerprint and HMAC.

- [ ] **Step 6: Bind Proposal verification to the packet.**

  Extend `verifyProposal()`/the Video branch of the existing Proposal binding validation so the Proposal's entity, operation, target, expected revision, canonical ID, source identity, subject packet, plan fingerprint, command fingerprint, Capture ID, and Capture fingerprint all equal the signed packet. Retain the existing generic `StagingAcceptanceScope::assertProposal()` checks.

- [ ] **Step 7: Run the verifier tests.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'StagingAcceptanceScopeVerifierTest'
  ```

  Expected: all verifier tests pass; admission-specific tests may still fail until Task 3 is complete.

- [ ] **Step 8: Commit the issuer/verifier slice.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Governance/StagingAcceptanceScopeVerifier.php public/wp-content/plugins/nhk-core/src/Infrastructure/Governance/GovernanceRuntimeFactory.php public/wp-content/plugins/nhk-core/tests/Unit/StagingAcceptanceScopeVerifierTest.php
  git commit -m "feat(video): bind dynamic staging scopes to exact plans"
  ```

### Task 3: Implement generic Video admission and composition wiring

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoStagingAdmission.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php`

**Interfaces:**
- Consumes: Task 2 packet shape, `CaptureRecord`, optional `VideoRepository`, and the existing staging admission filter signature.
- Produces: a provider that admits only exact Capture-owned Video ingest/update packets and rejects duplicates, wrong policy, wrong intent, wrong source, conflicts, and direct writers.

- [ ] **Step 1: Give the provider a nullable Video repository and preserve the filter signature.**

  Construct `VideoStagingAdmission(new WpdbVideoRepository($wpdb))` in `Plugin.php`. Keep the callable signature unchanged so WordPress filter composition and unit doubles remain compatible.

- [ ] **Step 2: Validate common packet policy.**

  Require approved staging packet, `PROJECT_BUILD`, `governed_video_plan`, `canonical_governed`, `nhk.capture.ingest`, exact Capture UUID/fingerprint, Capture intent `VIDEO`, valid operation `ingest|update`, valid UUIDs, and exact packet/plan/Proposal fingerprints. Reject `nhk.video.ingest`, non-Video entity types, client scope packets, and subject conflicts.

- [ ] **Step 3: Validate source and subject identity.**

  Read the sole Capture Video asset, derive the source snapshot and subject resolution packet, and compare platform/external ID/canonical URL, proposed/target UUID, subject type/UUID/revision, and plan fingerprint against the packet. Reject missing or ambiguous subject packets.

- [ ] **Step 4: Validate ingest duplicate state and update revision.**

  For ingest, require explicit create semantics and `findByExternalReference()` to return null. For update, require target UUID and expected revision to equal `findByCanonicalId()`'s current revision. Fail closed when the repository is unavailable or returns an identity conflict.

- [ ] **Step 5: Run admission tests.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'VideoStagingAdmissionTest'
  ```

  Expected: valid ingest/update and all tamper/duplicate/policy tests pass.

- [ ] **Step 6: Commit the generic provider slice.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Governance/VideoStagingAdmission.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php
  git commit -m "feat(video): admit exact capture-owned staging plans"
  ```

### Task 4: Attach scopes only from Capture-owned plans and preserve Governance boundaries

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/GovernanceAutomationExpansionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php`

**Interfaces:**
- Consumes: Task 2 issuer callback and Task 3 admission provider.
- Produces: Capture-owned `payload.staging_acceptance` for Video ingest/update and fail-closed Proposal eligibility/apply when it is absent, client-forged, stale, or mismatched.

- [ ] **Step 1: Expand `scopeVideoPlan()` operation selection.**

  Change the operation gate to `['ingest', 'update']` for automatic Capture scope issuance. Leave `retire` and `reactivate` outside this generic Video scope in this slice; their existing behavior remains unchanged.

- [ ] **Step 2: Remove connector scope influence.**

  Before issuing, remove `payload['staging_acceptance']` and any client-provided scope/fingerprint fields from the command material used for authorization. Attach only the returned server scope and its Capture fingerprint. The resulting Proposal command fingerprint must be computed from the server-owned plan.

- [ ] **Step 3: Add continuation tests for ingest and forged scope.**

  Assert a valid Video ingest receives a signed scope, a changed YouTube ID receives a different scope or fails, and a connector-provided approved packet is not carried into the Proposal. Assert direct Video writer input does not invoke the Capture scope issuer.

- [ ] **Step 4: Exercise eligibility and apply guards.**

  Build a Proposal with the exact scope and assert eligibility remains allowed in staging; remove or mutate the scope and assert `STAGING_SCOPE_REQUIRED`/`STAGING_SCOPE_NOT_APPROVED`; advance the canonical Video revision and assert apply fails with the existing CAS conflict without changing the packet.

- [ ] **Step 5: Run focused lifecycle tests.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'GovernedCaptureContinuationServiceTest|GovernanceAutomationExpansionTest|ProposalEligibilityServiceTest|ControlledApplyServiceTest'
  ```

  Expected: Capture scope attachment, eligibility, direct-writer isolation, and apply-time fail-closed behavior pass.

- [ ] **Step 6: Commit the lifecycle boundary slice.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Capture/GovernedCaptureContinuationService.php public/wp-content/plugins/nhk-core/tests/Unit/GovernedCaptureContinuationServiceTest.php public/wp-content/plugins/nhk-core/tests/Unit/GovernanceAutomationExpansionTest.php public/wp-content/plugins/nhk-core/tests/Unit/ProposalEligibilityServiceTest.php
  git commit -m "feat(video): attach scopes only to governed captures"
  ```

### Task 5: Prove W64 parity and 400-day generic ingest, then remove static admission

**Files:**
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/CaptureVideoProvenancePlannerTest.php`
- Modify: `public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php`
- Remove: `public/wp-content/plugins/nhk-core/src/Application/Governance/VideoW64StagingAdmission.php`
- Remove: `public/wp-content/plugins/nhk-core/tests/Unit/VideoW64StagingAdmissionTest.php`
- Modify: `public/wp-content/plugins/nhk-core/src/Plugin.php` if any legacy import/registration remains

**Interfaces:**
- Consumes: generic provider and issuer from Tasks 2–4.
- Produces: evidence that historical W64 and the 400-day Video work without object-specific runtime policy.

- [ ] **Step 1: Add the W64 generic-path regression.**

  Reuse the existing W64 fixture values only inside the test and route the plan through `StagingAcceptanceScopeVerifier` plus `VideoStagingAdmission`; assert no W64 class is instantiated or required.

- [ ] **Step 2: Add the 400-day generic-ingest regression.**

  Use YouTube ID `2EMuIG2RfTg`, the supplied Capture/proposed Video UUIDs, and the exact classification subject only as test data. Assert a scope is issued, Proposal verification succeeds, and the repository's duplicate lookup remains null. Keep these identifiers out of production code and configuration.

- [ ] **Step 3: Remove the obsolete static provider after parity is green.**

  Delete the W64 class/test only after the generic regressions pass. Remove any import or filter registration, then run `rg -n "VideoW64StagingAdmission|W64" public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests` and confirm remaining hits are historical evidence or test labels only.

- [ ] **Step 4: Run Video and Governance regression suites.**

  ```bash
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Video|CaptureVideo|GovernedCapture|StagingAcceptance|ProposalEligibility|ControlledApply|GovernanceAutomation'
  ```

  Expected: focused generic Video scope and lifecycle tests pass; unrelated pre-existing failures remain separately identified.

- [ ] **Step 5: Commit the parity/removal slice.**

  ```bash
  git add public/wp-content/plugins/nhk-core/src/Application/Governance/VideoW64StagingAdmission.php public/wp-content/plugins/nhk-core/tests/Unit/VideoW64StagingAdmissionTest.php public/wp-content/plugins/nhk-core/src/Plugin.php public/wp-content/plugins/nhk-core/tests/Unit/VideoStagingAdmissionTest.php
  git commit -m "refactor(video): remove static W64 staging admission"
  ```

### Task 6: Update execution evidence and run final local verification

**Files:**
- Modify: `docs/architecture/V3_EXECUTION_STATE.md`

**Interfaces:**
- Consumes: completed local implementation and test output from Tasks 1–5.
- Produces: a dated checkpoint proving local readiness, exact scope semantics, and no external mutation.

- [ ] **Step 1: Run changed-file PHP lint.**

  ```bash
  find public/wp-content/plugins/nhk-core/src public/wp-content/plugins/nhk-core/tests -name '*.php' -print0 | xargs -0 -n1 php -l
  ```

  Expected: every changed PHP file reports no syntax errors.

- [ ] **Step 2: Run repository lint and focused/full relevant tests.**

  ```bash
  composer lint
  vendor/bin/phpunit --configuration phpunit.xml.dist --filter 'Video|CaptureVideo|GovernedCapture|StagingAcceptance|ProposalEligibility|ControlledApply|GovernanceAutomation'
  ```

  Expected: changed-scope tests pass; any unrelated baseline failures are listed with their exact test names and not hidden.

- [ ] **Step 3: Run diff and secret checks.**

  ```bash
  git diff --check
  rg -n --hidden --glob '!vendor/**' --glob '!.git/**' 'NHK_STAGING_ACCEPTANCE_SCOPE_SECRET\s*=\s*[^$\s]|BEGIN (RSA|OPENSSH|EC) PRIVATE KEY|ghp_[A-Za-z0-9]{20,}|sk-[A-Za-z0-9]{20,}' .
  ```

  Expected: diff check is clean and the secret scan finds no credentials; the environment variable name itself is allowed.

- [ ] **Step 4: Update the execution checkpoint.**

  Append a dated entry to `docs/architecture/V3_EXECUTION_STATE.md` stating that generic dynamic Video staging acceptance is local-ready/deploy-pending, names the exact server-issued binding, confirms no wildcard permission, production/direct writer/other semantic owners remain blocked, and records test/lint/diff/secret results.

- [ ] **Step 5: Commit documentation and final evidence.**

  ```bash
  git add docs/architecture/V3_EXECUTION_STATE.md
  git commit -m "docs(video): record generic staging acceptance checkpoint"
  git status --short --branch
  ```

  Expected: only intentional local commits are ahead; no push or deployment occurs.

## Plan self-review

- Spec coverage: exact binding, ingest duplicate audit, update CAS, admission policy, Proposal verification, direct-writer isolation, static W64 removal, failure behavior, verification, and execution-state evidence are covered by Tasks 1–6.
- Placeholder scan: every step names its concrete file, symbol, command, expected outcome, and commit boundary.
- Type consistency: the existing `issueForVideoPlan(CaptureRecord, array): array`, filter callable signature, `VideoRepository` methods, and `Proposal` verification path remain the named interfaces throughout.
- Scope check: the work is one cohesive staging-acceptance boundary; no independent subsystem was introduced.
